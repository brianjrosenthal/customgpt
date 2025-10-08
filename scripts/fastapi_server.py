#!/usr/bin/env python3
"""
FastAPI server for CustomGPT background processing tasks.
This server provides API endpoints for chunk generation and embedding creation,
with built-in job management and concurrency control.
"""

import asyncio
import uuid
import sys
import os
import traceback
from datetime import datetime
from typing import Dict, Optional, List
from pathlib import Path

from fastapi import FastAPI, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel
import uvicorn

# Import heavy libraries at startup to avoid blocking on first request
from langchain_openai import OpenAIEmbeddings
from langchain_community.vectorstores import FAISS
from sentence_transformers import CrossEncoder
from openai import OpenAI
import json
import numpy as np

# Import our existing processing modules
from generate_chunks import (
    get_db_connection as chunks_get_db_connection,
    process_document,
    log as chunks_log
)
from generate_embeddings import (
    get_db_connection as embeddings_get_db_connection,
    get_openai_config,
    load_chunks,
    calculate_checksum,
    batch_embed_chunks,
    serialize_faiss_index,
    upsert_embeddings,
    log as embeddings_log
)

# Try to load config from PHP config file
try:
    import re
    config_file = Path(__file__).parent.parent / 'config.local.php'
    if config_file.exists():
        with open(config_file, 'r') as f:
            content = f.read()
        
        # Extract FastAPI configuration
        port_match = re.search(r"define\('FASTAPI_PORT',\s*(\d+)\)", content)
        max_jobs_match = re.search(r"define\('FASTAPI_MAX_CONCURRENT_JOBS',\s*(\d+)\)", content)
        
        FASTAPI_PORT = int(port_match.group(1)) if port_match else 8001
        MAX_CONCURRENT_JOBS = int(max_jobs_match.group(1)) if max_jobs_match else 2
    else:
        FASTAPI_PORT = 8001
        MAX_CONCURRENT_JOBS = 2
except Exception:
    FASTAPI_PORT = 8001
    MAX_CONCURRENT_JOBS = 2

# Initialize FastAPI app
app = FastAPI(
    title="CustomGPT Processing Service",
    description="Background processing service for document chunking and embedding generation",
    version="1.0.0"
)

# Job storage and management
jobs: Dict[str, dict] = {}
job_logs: Dict[str, List[str]] = {}
active_jobs_semaphore = asyncio.Semaphore(MAX_CONCURRENT_JOBS)

# Global pre-loaded models (Phase 2: Pre-load at startup)
cross_encoder_model = None
openai_embeddings = None


@app.on_event("startup")
async def startup_event():
    """Pre-load heavy models at startup to avoid blocking on first request."""
    global cross_encoder_model, openai_embeddings
    
    print("Loading models at startup...")
    
    # Load cross-encoder model
    print("  Loading cross-encoder model...")
    cross_encoder_model = CrossEncoder('cross-encoder/ms-marco-MiniLM-L-6-v2')
    print("  ✓ Cross-encoder model loaded")
    
    # Load OpenAI embeddings
    try:
        print("  Loading OpenAI embeddings...")
        api_key, embed_model = get_openai_config()
        openai_embeddings = OpenAIEmbeddings(
            openai_api_key=api_key,
            model=embed_model
        )
        print(f"  ✓ OpenAI embeddings loaded ({embed_model})")
    except Exception as e:
        print(f"  ⚠ Warning: Could not load OpenAI embeddings: {e}")
    
    print("✓ All models loaded successfully")


class JobStatus(BaseModel):
    """Job status response model."""
    job_id: str
    status: str  # 'queued', 'running', 'completed', 'failed'
    customgpt_id: int
    task_type: str  # 'chunks' or 'embeddings'
    started_at: Optional[str] = None
    completed_at: Optional[str] = None
    error: Optional[str] = None
    progress: Optional[str] = None


class ChunkGenerationRequest(BaseModel):
    """Chunk generation request model."""
    chunk_size: int = 1000
    chunk_overlap: int = 200


class RetrievalRequest(BaseModel):
    """Retrieval request model."""
    query: str
    top_k: int = 10


class RetrievalResponse(BaseModel):
    """Retrieval response model."""
    chunks: List[dict]
    query: str
    total_results: int


class RerankRequest(BaseModel):
    """Re-ranking request model."""
    query: str
    chunks: List[dict]


class RerankResponse(BaseModel):
    """Re-ranking response model."""
    reranked_chunks: List[dict]
    query: str


class PromptGenerationRequest(BaseModel):
    """Prompt generation request model."""
    query: str
    reranked_chunks: List[dict]


class PromptGenerationResponse(BaseModel):
    """Prompt generation response model."""
    prompt: str
    context_documents: List[dict]


class ChatGPTQueryRequest(BaseModel):
    """ChatGPT query request model."""
    prompt: str


class ChatGPTQueryResponse(BaseModel):
    """ChatGPT query response model."""
    response: str
    model: str
    tokens_used: Optional[int] = None


class QueryExecutionRequest(BaseModel):
    """Full query execution request model."""
    query: str
    top_k: int = 10


class QueryJobStatus(BaseModel):
    """Query job status response model."""
    job_id: str
    status: str  # 'queued', 'retrieving', 'reranking', 'generating_prompt', 'querying_chatgpt', 'completed', 'failed'
    status_message: str
    result: Optional[str] = None
    error: Optional[str] = None


class JobCreateResponse(BaseModel):
    """Job creation response model."""
    job_id: str
    status: str
    message: str


def log_to_job(job_id: str, message: str) -> None:
    """Add a log message to a job's log buffer."""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    log_message = f"[{timestamp}] {message}"
    
    if job_id not in job_logs:
        job_logs[job_id] = []
    
    job_logs[job_id].append(log_message)
    
    # Also write to the log file if specified in the job
    if job_id in jobs and 'log_file' in jobs[job_id]:
        log_file = jobs[job_id]['log_file']
        try:
            with open(log_file, 'a') as f:
                f.write(log_message + '\n')
        except Exception:
            pass


async def process_chunks_task(job_id: str, customgpt_id: int, log_file: str, chunk_size: int = 1000, chunk_overlap: int = 200) -> None:
    """Background task to process document chunks."""
    async with active_jobs_semaphore:
        try:
            jobs[job_id]['status'] = 'running'
            jobs[job_id]['started_at'] = datetime.now().isoformat()
            log_to_job(job_id, f"Starting chunk generation for CustomGPT ID: {customgpt_id}")
            log_to_job(job_id, f"Configuration: chunk_size={chunk_size}, chunk_overlap={chunk_overlap}")
            
            # Initialize log file
            with open(log_file, 'w') as f:
                f.write(f"Chunk Generation Log - CustomGPT ID: {customgpt_id}\n")
                f.write(f"Chunk Size: {chunk_size}, Overlap: {chunk_overlap}\n")
                f.write("=" * 60 + "\n\n")
            
            # Connect to database
            connection = chunks_get_db_connection()
            log_to_job(job_id, "Database connection established")
            
            with connection.cursor() as cursor:
                # Get all documents for this CustomGPT
                cursor.execute(
                    """SELECT d.id, d.file_id, sf.original_filename
                       FROM customgpt_documents d
                       JOIN secure_files sf ON d.file_id = sf.id
                       WHERE d.customgpt_id = %s
                       ORDER BY d.created_at""",
                    (customgpt_id,)
                )
                documents = cursor.fetchall()
                
                if not documents:
                    log_to_job(job_id, "No documents found for this CustomGPT")
                    jobs[job_id]['status'] = 'completed'
                    jobs[job_id]['completed_at'] = datetime.now().isoformat()
                    return
                
                log_to_job(job_id, f"Found {len(documents)} document(s) to process")
                
                # Process each document with custom chunk configuration
                success_count = 0
                for idx, doc in enumerate(documents, 1):
                    log_to_job(job_id, f"Document {idx}/{len(documents)}:")
                    if process_document(cursor, doc['id'], doc['file_id'], log_file, chunk_size, chunk_overlap):
                        success_count += 1
                    
                    # Update progress
                    progress = f"{idx}/{len(documents)} documents processed"
                    jobs[job_id]['progress'] = progress
                
                # Commit all changes
                connection.commit()
                log_to_job(job_id, "All changes committed to database")
            
            connection.close()
            log_to_job(job_id, "")
            log_to_job(job_id, "=" * 60)
            log_to_job(job_id, "✓ Chunk generation completed!")
            log_to_job(job_id, f"  Successfully processed: {success_count}/{len(documents)} documents")
            
            jobs[job_id]['status'] = 'completed'
            jobs[job_id]['completed_at'] = datetime.now().isoformat()
            
        except Exception as e:
            error_msg = f"ERROR: {str(e)}"
            log_to_job(job_id, "")
            log_to_job(job_id, "=" * 60)
            log_to_job(job_id, error_msg)
            log_to_job(job_id, traceback.format_exc())
            
            jobs[job_id]['status'] = 'failed'
            jobs[job_id]['error'] = error_msg
            jobs[job_id]['completed_at'] = datetime.now().isoformat()


async def process_embeddings_task(job_id: str, customgpt_id: int, log_file: str) -> None:
    """Background task to process vector embeddings."""
    async with active_jobs_semaphore:
        try:
            jobs[job_id]['status'] = 'running'
            jobs[job_id]['started_at'] = datetime.now().isoformat()
            log_to_job(job_id, f"Starting vector embeddings generation for CustomGPT ID: {customgpt_id}")
            
            # Initialize log file
            with open(log_file, 'w') as f:
                f.write(f"Vector Embeddings Generation Log - CustomGPT ID: {customgpt_id}\n")
                f.write("=" * 60 + "\n\n")
            
            # Get OpenAI configuration
            api_key, embed_model = get_openai_config()
            log_to_job(job_id, f"Using embedding model: {embed_model}")
            
            # Connect to database
            connection = embeddings_get_db_connection()
            log_to_job(job_id, "Database connection established")
            
            # Import here to avoid loading heavy libraries at startup
            from langchain_openai import OpenAIEmbeddings
            from langchain_community.vectorstores import FAISS
            
            with connection.cursor() as cursor:
                # Load all chunks
                chunks = load_chunks(cursor, customgpt_id)
                
                if not chunks:
                    log_to_job(job_id, "No chunks found for this CustomGPT")
                    log_to_job(job_id, "Please run generate_chunks.py first to create chunks")
                    jobs[job_id]['status'] = 'completed'
                    jobs[job_id]['completed_at'] = datetime.now().isoformat()
                    return
                
                log_to_job(job_id, f"Found {len(chunks)} chunk(s) to embed")
                
                # Calculate checksum
                checksum = calculate_checksum(chunks)
                log_to_job(job_id, f"Chunk checksum: {checksum}")
                
                # Initialize OpenAI embeddings
                embeddings = OpenAIEmbeddings(
                    openai_api_key=api_key,
                    model=embed_model
                )
                
                # Embed chunks in batches with error handling
                log_to_job(job_id, "Embedding chunks...")
                successful_chunks, failed_chunk_ids = batch_embed_chunks(
                    embeddings, chunks, batch_size=100, log_file=log_file, cursor=cursor
                )
                
                if not successful_chunks:
                    log_to_job(job_id, "")
                    log_to_job(job_id, "✗ All chunks failed to embed. Cannot create vector store.")
                    connection.commit()
                    jobs[job_id]['status'] = 'failed'
                    jobs[job_id]['error'] = 'All chunks failed to embed'
                    jobs[job_id]['completed_at'] = datetime.now().isoformat()
                    return
                
                log_to_job(job_id, "")
                log_to_job(job_id, f"Successfully embedded {len(successful_chunks)}/{len(chunks)} chunks")
                if failed_chunk_ids:
                    log_to_job(job_id, f"Failed chunks: {len(failed_chunk_ids)}")
                
                # Update progress
                jobs[job_id]['progress'] = f"{len(successful_chunks)}/{len(chunks)} chunks embedded"
                
                # Build FAISS vector store
                log_to_job(job_id, "Building FAISS vector store...")
                texts = [chunk['text'] for chunk in successful_chunks]
                metadatas = [
                    {
                        'chunk_id': chunk['id'],
                        'document_id': chunk['customgpt_document_id'],
                        'sort_order': chunk['sort_order'],
                        'filename': chunk['original_filename']
                    }
                    for chunk in successful_chunks
                ]
                
                vectorstore = FAISS.from_texts(
                    texts=texts,
                    embedding=embeddings,
                    metadatas=metadatas
                )
                log_to_job(job_id, f"✓ FAISS index created with {vectorstore.index.ntotal} vectors")
                
                # Serialize the vector store
                log_to_job(job_id, "Serializing vector store...")
                faiss_bytes, id_map_json, docstore_json = serialize_faiss_index(vectorstore)
                log_to_job(job_id, f"  FAISS index size: {len(faiss_bytes):,} bytes")
                
                # Save to database
                log_to_job(job_id, "Saving to database...")
                upsert_embeddings(
                    cursor, customgpt_id, embed_model, faiss_bytes,
                    id_map_json, docstore_json, checksum
                )
                
                # Commit all changes
                connection.commit()
                log_to_job(job_id, "✓ All changes committed to database")
            
            connection.close()
            log_to_job(job_id, "")
            log_to_job(job_id, "=" * 60)
            log_to_job(job_id, "✓ Vector embeddings generation completed!")
            log_to_job(job_id, f"  Total chunks: {len(chunks)}")
            log_to_job(job_id, f"  Successfully embedded: {len(successful_chunks)}")
            log_to_job(job_id, f"  Failed: {len(failed_chunk_ids)}")
            
            jobs[job_id]['status'] = 'completed'
            jobs[job_id]['completed_at'] = datetime.now().isoformat()
            
        except Exception as e:
            error_msg = f"ERROR: {str(e)}"
            log_to_job(job_id, "")
            log_to_job(job_id, "=" * 60)
            log_to_job(job_id, error_msg)
            log_to_job(job_id, traceback.format_exc())
            
            jobs[job_id]['status'] = 'failed'
            jobs[job_id]['error'] = error_msg
            jobs[job_id]['completed_at'] = datetime.now().isoformat()


@app.get("/")
async def root():
    """Root endpoint."""
    return {
        "service": "CustomGPT Processing Service",
        "version": "1.0.0",
        "status": "running"
    }


@app.get("/api/v1/health")
async def health_check():
    """Health check endpoint."""
    return {
        "status": "healthy",
        "timestamp": datetime.now().isoformat(),
        "max_concurrent_jobs": MAX_CONCURRENT_JOBS
    }


@app.get("/api/v1/status")
async def service_status():
    """Get service status including active jobs."""
    active_jobs = [job for job in jobs.values() if job['status'] in ['queued', 'running']]
    completed_jobs = [job for job in jobs.values() if job['status'] == 'completed']
    failed_jobs = [job for job in jobs.values() if job['status'] == 'failed']
    
    return {
        "status": "running",
        "max_concurrent_jobs": MAX_CONCURRENT_JOBS,
        "active_jobs": len(active_jobs),
        "completed_jobs": len(completed_jobs),
        "failed_jobs": len(failed_jobs),
        "total_jobs": len(jobs)
    }


@app.post("/api/v1/chunks/generate/{customgpt_id}", response_model=JobCreateResponse)
async def generate_chunks(customgpt_id: int, request: ChunkGenerationRequest):
    """Start chunk generation for a CustomGPT."""
    # Create unique job ID
    job_id = str(uuid.uuid4())
    
    # Create log file path
    log_file = f"/tmp/chunk_generation_{customgpt_id}_{job_id}.log"
    
    # Create job record
    jobs[job_id] = {
        'job_id': job_id,
        'status': 'queued',
        'customgpt_id': customgpt_id,
        'task_type': 'chunks',
        'log_file': log_file,
        'chunk_size': request.chunk_size,
        'chunk_overlap': request.chunk_overlap,
        'created_at': datetime.now().isoformat()
    }
    
    # Start background task asynchronously (returns immediately)
    asyncio.create_task(process_chunks_task(job_id, customgpt_id, log_file, request.chunk_size, request.chunk_overlap))
    
    return JobCreateResponse(
        job_id=job_id,
        status='queued',
        message=f'Chunk generation job created for CustomGPT {customgpt_id}'
    )


@app.post("/api/v1/embeddings/generate/{customgpt_id}", response_model=JobCreateResponse)
async def generate_embeddings(customgpt_id: int):
    """Start embedding generation for a CustomGPT."""
    # Create unique job ID
    job_id = str(uuid.uuid4())
    
    # Create log file path
    log_file = f"/tmp/embedding_generation_{customgpt_id}_{job_id}.log"
    
    # Create job record
    jobs[job_id] = {
        'job_id': job_id,
        'status': 'queued',
        'customgpt_id': customgpt_id,
        'task_type': 'embeddings',
        'log_file': log_file,
        'created_at': datetime.now().isoformat()
    }
    
    # Start background task asynchronously (returns immediately)
    asyncio.create_task(process_embeddings_task(job_id, customgpt_id, log_file))
    
    return JobCreateResponse(
        job_id=job_id,
        status='queued',
        message=f'Embedding generation job created for CustomGPT {customgpt_id}'
    )


@app.get("/api/v1/jobs/{job_id}/status", response_model=JobStatus)
async def get_job_status(job_id: str):
    """Get the status of a specific job."""
    if job_id not in jobs:
        raise HTTPException(status_code=404, detail="Job not found")
    
    job = jobs[job_id]
    return JobStatus(**job)


@app.get("/api/v1/jobs/{job_id}/logs")
async def get_job_logs(job_id: str):
    """Get the logs for a specific job."""
    if job_id not in jobs:
        raise HTTPException(status_code=404, detail="Job not found")
    
    logs = job_logs.get(job_id, [])
    
    # Also read from log file if it exists
    if 'log_file' in jobs[job_id]:
        log_file = jobs[job_id]['log_file']
        try:
            if os.path.exists(log_file):
                with open(log_file, 'r') as f:
                    file_logs = f.read()
                return {
                    "job_id": job_id,
                    "logs": logs,
                    "log_file": log_file,
                    "file_content": file_logs
                }
        except Exception:
            pass
    
    return {
        "job_id": job_id,
        "logs": logs
    }


@app.post("/api/v1/retrieve/{customgpt_id}", response_model=RetrievalResponse)
async def retrieve_chunks(customgpt_id: int, request: RetrievalRequest):
    """Retrieve relevant chunks for a query using semantic search."""
    try:
        # Import heavy libraries only when needed
        from langchain_openai import OpenAIEmbeddings
        from langchain_community.vectorstores import FAISS
        import json
        import numpy as np
        
        # Get OpenAI configuration
        api_key, embed_model = get_openai_config()
        
        # Connect to database
        connection = embeddings_get_db_connection()
        
        with connection.cursor() as cursor:
            # Load embeddings from database
            cursor.execute(
                """SELECT faiss_bytes, id_map_json, docstore_json, embed_model
                   FROM customgpt_vector_embeddings
                   WHERE customgpt_id = %s
                   ORDER BY updated_at DESC
                   LIMIT 1""",
                (customgpt_id,)
            )
            embeddings_row = cursor.fetchone()
            
            if not embeddings_row:
                raise HTTPException(
                    status_code=404,
                    detail="No embeddings found for this CustomGPT. Please generate embeddings first."
                )
            
            # Deserialize FAISS index
            faiss_bytes = embeddings_row['faiss_bytes']
            id_map_json = embeddings_row['id_map_json']
            docstore_json = embeddings_row['docstore_json']
            
            # Import faiss helper
            from faiss_helper import deserialize_faiss_index
            
            # Initialize embeddings
            embeddings = OpenAIEmbeddings(
                openai_api_key=api_key,
                model=embed_model
            )
            
            # Deserialize the vector store
            vectorstore = deserialize_faiss_index(faiss_bytes, id_map_json, docstore_json, embeddings)
            
            # Perform similarity search with scores
            docs_with_scores = vectorstore.similarity_search_with_score(
                request.query,
                k=request.top_k
            )
            
            # Format results
            results = []
            for doc, score in docs_with_scores:
                # Extract metadata
                metadata = doc.metadata
                chunk_id = metadata.get('chunk_id')
                document_id = metadata.get('document_id')
                filename = metadata.get('filename', '')
                
                # Calculate similarity percentage (FAISS uses L2 distance, lower is better)
                # Convert to similarity score (0-100%)
                # For L2 distance, we use: similarity = 1 / (1 + distance)
                similarity_percent = (1 / (1 + score)) * 100
                
                results.append({
                    'text': doc.page_content,
                    'score': float(score),
                    'similarity_percent': float(similarity_percent),
                    'chunk_id': chunk_id,
                    'document_id': document_id,
                    'filename': filename
                })
        
        connection.close()
        
        return RetrievalResponse(
            chunks=results,
            query=request.query,
            total_results=len(results)
        )
        
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"Error during retrieval: {str(e)}"
        )


@app.post("/api/v1/rerank/{customgpt_id}", response_model=RerankResponse)
async def rerank_chunks(customgpt_id: int, request: RerankRequest):
    """Re-rank chunks using cross-encoder model."""
    try:
        # Import cross-encoder library
        from sentence_transformers import CrossEncoder
        
        # Load cross-encoder model
        model = CrossEncoder('cross-encoder/ms-marco-MiniLM-L-6-v2')
        
        # Prepare query-document pairs
        pairs = [[request.query, chunk['text']] for chunk in request.chunks]
        
        # Score all pairs
        scores = model.predict(pairs)
        
        # Combine chunks with their cross-encoder scores and original rank
        scored_chunks = []
        for idx, (chunk, score) in enumerate(zip(request.chunks, scores)):
            scored_chunk = chunk.copy()
            scored_chunk['cross_encoder_score'] = float(score)
            scored_chunk['original_rank'] = idx + 1
            scored_chunks.append(scored_chunk)
        
        # Sort by cross-encoder score (descending)
        reranked_chunks = sorted(scored_chunks, key=lambda x: x['cross_encoder_score'], reverse=True)
        
        return RerankResponse(
            reranked_chunks=reranked_chunks,
            query=request.query
        )
        
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"Error during re-ranking: {str(e)}"
        )


@app.post("/api/v1/generate-prompt/{customgpt_id}", response_model=PromptGenerationResponse)
async def generate_prompt(customgpt_id: int, request: PromptGenerationRequest):
    """Generate a context-rich prompt from reranked chunks."""
    try:
        MIN_PERCENT_SCORE = 40.0
        
        # Connect to database
        connection = embeddings_get_db_connection()
        
        with connection.cursor() as cursor:
            # Filter chunks by minimum score and take top 3
            qualifying_chunks = [
                chunk for chunk in request.reranked_chunks
                if (chunk['cross_encoder_score'] * 100) >= MIN_PERCENT_SCORE
            ][:3]
            
            if not qualifying_chunks:
                # No chunks meet the minimum threshold
                prompt = f"""You are an operations advisor, offering lessons from previous events to inform the event or question in the prompt. Use ONLY the post-mortem excerpts provided.

New event / question:
{request.query}

Context (each block is a different prior incident)

No qualifying documents found (all documents scored below {MIN_PERCENT_SCORE}% relevance).

Instructions:
I don't have a strong match in prior post-mortems for this query."""
                
                return PromptGenerationResponse(
                    prompt=prompt,
                    context_documents=[]
                )
            
            # Expand each chunk to document context
            context_docs = []
            seen_documents = set()
            
            for chunk in qualifying_chunks:
                chunk_id = chunk['chunk_id']
                document_id = chunk['document_id']
                
                # Skip if we've already processed this document
                if document_id in seen_documents:
                    continue
                
                seen_documents.add(document_id)
                
                # Get chunk details
                cursor.execute(
                    """SELECT customgpt_document_id, sort_order
                       FROM customgpt_document_chunks
                       WHERE id = %s""",
                    (chunk_id,)
                )
                chunk_row = cursor.fetchone()
                
                if not chunk_row:
                    continue
                
                customgpt_document_id = chunk_row['customgpt_document_id']
                target_sort_order = chunk_row['sort_order']
                
                # Count total chunks in document
                cursor.execute(
                    """SELECT COUNT(*) as total
                       FROM customgpt_document_chunks
                       WHERE customgpt_document_id = %s""",
                    (customgpt_document_id,)
                )
                total_chunks = cursor.fetchone()['total']
                
                # Get filename
                cursor.execute(
                    """SELECT sf.original_filename
                       FROM customgpt_documents d
                       JOIN secure_files sf ON d.file_id = sf.id
                       WHERE d.id = %s""",
                    (customgpt_document_id,)
                )
                filename_row = cursor.fetchone()
                filename = filename_row['original_filename'] if filename_row else f"Document {customgpt_document_id}"
                
                # Determine which chunks to fetch
                if total_chunks <= 10:
                    # Fetch entire document
                    cursor.execute(
                        """SELECT text
                           FROM customgpt_document_chunks
                           WHERE customgpt_document_id = %s
                           ORDER BY sort_order""",
                        (customgpt_document_id,)
                    )
                else:
                    # Fetch: first chunk + 5 before target + target + 1 after
                    # Get first chunk
                    cursor.execute(
                        """SELECT text
                           FROM customgpt_document_chunks
                           WHERE customgpt_document_id = %s
                           ORDER BY sort_order
                           LIMIT 1""",
                        (customgpt_document_id,)
                    )
                    first_chunk = cursor.fetchall()
                    
                    # Get contextual chunks around target
                    start_order = max(0, target_sort_order - 5)
                    end_order = target_sort_order + 1
                    
                    cursor.execute(
                        """SELECT text
                           FROM customgpt_document_chunks
                           WHERE customgpt_document_id = %s
                           AND sort_order BETWEEN %s AND %s
                           ORDER BY sort_order""",
                        (customgpt_document_id, start_order, end_order)
                    )
                    contextual_chunks = cursor.fetchall()
                    
                    # Combine, avoiding duplicates if first chunk is in range
                    all_chunks = first_chunk
                    if start_order > 0:
                        all_chunks.extend(contextual_chunks)
                    else:
                        # First chunk already included
                        all_chunks.extend(contextual_chunks[1:])
                    
                    cursor.execute(
                        """SELECT 1 FROM 
                           (SELECT text FROM customgpt_document_chunks
                            WHERE customgpt_document_id = %s
                            ORDER BY sort_order LIMIT 1) as first
                           WHERE 1=1""",
                        (customgpt_document_id,)
                    )
                    # Re-fetch with proper combination
                    cursor.execute(
                        """SELECT text
                           FROM customgpt_document_chunks
                           WHERE customgpt_document_id = %s
                           AND (sort_order = 0 OR sort_order BETWEEN %s AND %s)
                           ORDER BY sort_order""",
                        (customgpt_document_id, start_order, end_order)
                    )
                
                chunk_texts = cursor.fetchall()
                document_text = '\n\n'.join([row['text'] for row in chunk_texts])
                
                context_docs.append({
                    'document_id': customgpt_document_id,
                    'filename': filename,
                    'text': document_text,
                    'chunk_count': len(chunk_texts)
                })
            
            # Build context string
            context_str = ""
            for idx, doc in enumerate(context_docs, 1):
                context_str += f"DOCUMENT #{idx} (ID: {doc['document_id']})\n"
                context_str += doc['text']
                context_str += "\n-----\n\n"
            
            # Build document links map
            doc_links_map = ""
            for idx, doc in enumerate(context_docs, 1):
                doc_links_map += f"Document #{idx}: /customgpt_documents/download_file.php?id={doc['document_id']}\n"
            
            # Build the prompt
            prompt = f"""You are an operations advisor, offering lessons from previous events to inform the event or question in the prompt. Use ONLY the post-mortem excerpts provided.

New event / question:
{request.query}

Context (each block is a different prior incident)

{context_str}
Instructions:
- Identify 0-3 lessons directly supported by the context.
- For each lesson include: Venue, Date, 1–2 sentence summary, and an actionable takeaway.
- Cite the supporting blocks inline with links. Here is the map of documents to links:

{doc_links_map}
- If the context is weak, say: "I don't have a strong match in prior post-mortems."

If the question is a direct question and there is an answer in the documents, just try to answer it.
If the question is not a direct question but just an event description, use the following template:
1) Key Risks to Watch
2) Likely Relevant Incidents"""
            
        connection.close()
        
        return PromptGenerationResponse(
            prompt=prompt,
            context_documents=context_docs
        )
        
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"Error generating prompt: {str(e)}"
        )


@app.post("/api/v1/query-chatgpt/{customgpt_id}", response_model=ChatGPTQueryResponse)
async def query_chatgpt(customgpt_id: int, request: ChatGPTQueryRequest):
    """Query ChatGPT with the generated prompt."""
    try:
        # Import OpenAI
        from openai import OpenAI
        
        # Get OpenAI configuration
        api_key, _ = get_openai_config()
        
        # Initialize OpenAI client
        client = OpenAI(api_key=api_key)
        
        # Use GPT-4 Turbo (latest available model)
        model = "gpt-4-turbo-preview"
        
        # Make API call to OpenAI
        response = client.chat.completions.create(
            model=model,
            messages=[
                {"role": "system", "content": "You are a helpful operations advisor with expertise in event management and post-mortem analysis."},
                {"role": "user", "content": request.prompt}
            ],
            temperature=0.7,
            max_tokens=2000
        )
        
        # Extract response
        assistant_message = response.choices[0].message.content
        tokens_used = response.usage.total_tokens if response.usage else None
        
        return ChatGPTQueryResponse(
            response=assistant_message,
            model=model,
            tokens_used=tokens_used
        )
        
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"Error querying ChatGPT: {str(e)}"
        )


# Query job storage (separate from chunk/embedding jobs)
query_jobs: Dict[str, dict] = {}


async def process_query_execution(job_id: str, customgpt_id: int, query: str, top_k: int = 10):
    """Execute full query pipeline: retrieval -> reranking -> prompt -> chatgpt."""
    try:
        # DIAGNOSTIC: Artificial delay to test async behavior
        query_jobs[job_id]['status'] = 'testing'
        query_jobs[job_id]['status_message'] = 'Artificial delay (20 seconds)...'
        print(f"[DEBUG] Job {job_id}: Starting 20-second artificial delay")
        await asyncio.sleep(20)
        print(f"[DEBUG] Job {job_id}: Artificial delay complete")
        
        query_jobs[job_id]['status'] = 'retrieving'
        query_jobs[job_id]['status_message'] = 'Running retrieval algorithm...'
        print(f"[DEBUG] Job {job_id}: Starting retrieval")
        
        # Step 1: Retrieval
        retrieval_request = RetrievalRequest(query=query, top_k=top_k)
        retrieval_result = await retrieve_chunks(customgpt_id, retrieval_request)
        
        query_jobs[job_id]['status'] = 'reranking'
        query_jobs[job_id]['status_message'] = 'Running re-ranking algorithm...'
        
        # Step 2: Re-ranking
        rerank_request = RerankRequest(query=query, chunks=retrieval_result.chunks)
        rerank_result = await rerank_chunks(customgpt_id, rerank_request)
        
        query_jobs[job_id]['status'] = 'generating_prompt'
        query_jobs[job_id]['status_message'] = 'Generating context-rich prompt...'
        
        # Step 3: Prompt Generation
        prompt_request = PromptGenerationRequest(query=query, reranked_chunks=rerank_result.reranked_chunks)
        prompt_result = await generate_prompt(customgpt_id, prompt_request)
        
        query_jobs[job_id]['status'] = 'querying_chatgpt'
        query_jobs[job_id]['status_message'] = 'Querying ChatGPT...'
        
        # Step 4: ChatGPT Query
        chatgpt_request = ChatGPTQueryRequest(prompt=prompt_result.prompt)
        chatgpt_result = await query_chatgpt(customgpt_id, chatgpt_request)
        
        # Success!
        query_jobs[job_id]['status'] = 'completed'
        query_jobs[job_id]['status_message'] = 'Query execution completed successfully'
        query_jobs[job_id]['result'] = chatgpt_result.response
        query_jobs[job_id]['completed_at'] = datetime.now().isoformat()
        
    except Exception as e:
        query_jobs[job_id]['status'] = 'failed'
        query_jobs[job_id]['status_message'] = 'Query execution failed'
        query_jobs[job_id]['error'] = str(e)
        query_jobs[job_id]['completed_at'] = datetime.now().isoformat()


@app.post("/api/v1/execute-query/{customgpt_id}")
async def execute_query(customgpt_id: int, request: QueryExecutionRequest):
    """Start full query execution pipeline."""
    try:
        print(f"[DEBUG] execute_query endpoint called for customgpt_id={customgpt_id}")
        job_id = str(uuid.uuid4())
        print(f"[DEBUG] Generated job_id: {job_id}")
        
        # Create job record FIRST before any other work
        query_jobs[job_id] = {
            'job_id': job_id,
            'status': 'queued',
            'status_message': 'Query execution starting...',
            'customgpt_id': customgpt_id,
            'query': request.query,
            'created_at': datetime.now().isoformat(),
            'result': None,
            'error': None
        }
        print(f"[DEBUG] Job record created for {job_id}")
        
        # Start background task (non-blocking)
        print(f"[DEBUG] Creating background task for {job_id}")
        asyncio.create_task(process_query_execution(job_id, customgpt_id, request.query, request.top_k))
        print(f"[DEBUG] Background task created, returning response for {job_id}")
        
        # Return immediately
        return {
            'job_id': job_id,
            'status': 'queued',
            'message': 'Query execution started'
        }
    except Exception as e:
        print(f"[DEBUG] Exception in execute_query: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Failed to start query execution: {str(e)}")


@app.get("/api/v1/query-jobs/{job_id}/status", response_model=QueryJobStatus)
async def get_query_job_status(job_id: str):
    """Get query job status."""
    if job_id not in query_jobs:
        raise HTTPException(status_code=404, detail="Job not found")
    
    job = query_jobs[job_id]
    return QueryJobStatus(**job)


def main():
    """Main entry point."""
    print(f"Starting CustomGPT Processing Service on port {FASTAPI_PORT}")
    print(f"Max concurrent jobs: {MAX_CONCURRENT_JOBS}")
    
    uvicorn.run(
        app,
        host="0.0.0.0",
        port=FASTAPI_PORT,
        log_level="info"
    )


if __name__ == "__main__":
    main()
