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
