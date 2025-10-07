#!/usr/bin/env python3
"""
Generate vector embeddings for CustomGPT document chunks using OpenAI and FAISS.
This script processes all chunks for a given CustomGPT ID and creates a 
searchable vector store for retrieval-augmented generation.
"""

import sys
import os
import pymysql
import json
import hashlib
import io
import tempfile
from datetime import datetime
from python_config import DB_CONFIG

# LangChain imports
try:
    from langchain_openai import OpenAIEmbeddings
    from langchain_community.vectorstores import FAISS
    from langchain.docstore.document import Document
    import faiss
except ImportError as e:
    print(f"Error importing required libraries: {e}")
    print("Please install: pip install langchain-openai langchain-community faiss-cpu")
    sys.exit(1)


def log(log_file, message):
    """Write a log message to both stdout and the log file."""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    log_message = f"[{timestamp}] {message}"
    print(log_message)
    if log_file:
        with open(log_file, 'a') as f:
            f.write(log_message + '\n')


def get_db_connection():
    """Create and return a database connection."""
    return pymysql.connect(
        host=DB_CONFIG['host'],
        user=DB_CONFIG['user'],
        password=DB_CONFIG['password'],
        database=DB_CONFIG['database'],
        charset=DB_CONFIG['charset'],
        cursorclass=pymysql.cursors.DictCursor
    )


def get_openai_config():
    """Get OpenAI configuration from environment or config file."""
    api_key = os.environ.get('OPENAI_API_KEY')
    embed_model = os.environ.get('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small')
    
    if not api_key:
        # Try to load from PHP config file
        try:
            import re
            config_file = os.path.join(os.path.dirname(__file__), '..', 'config.local.php')
            if os.path.exists(config_file):
                with open(config_file, 'r') as f:
                    content = f.read()
                api_key_match = re.search(r"define\('OPENAI_API_KEY',\s*'([^']*)'\)", content)
                model_match = re.search(r"define\('OPENAI_EMBEDDING_MODEL',\s*'([^']*)'\)", content)
                if api_key_match:
                    api_key = api_key_match.group(1)
                if model_match:
                    embed_model = model_match.group(1)
        except Exception as e:
            pass
    
    if not api_key:
        raise ValueError("OPENAI_API_KEY not found in environment or config.local.php")
    
    return api_key, embed_model


def load_chunks(cursor, customgpt_id):
    """Load all chunks for a CustomGPT."""
    cursor.execute(
        """SELECT c.id, c.text, c.customgpt_document_id, c.sort_order,
                  d.file_id, sf.original_filename
           FROM customgpt_document_chunks c
           JOIN customgpt_documents d ON c.customgpt_document_id = d.id
           JOIN secure_files sf ON d.file_id = sf.id
           WHERE d.customgpt_id = %s
           ORDER BY c.customgpt_document_id, c.sort_order""",
        (customgpt_id,)
    )
    return cursor.fetchall()


def mark_chunk_error(cursor, chunk_id, error_message):
    """Mark a chunk as having an embedding error."""
    cursor.execute(
        """UPDATE customgpt_document_chunks 
           SET embedding_error = %s, embedding_attempted_at = NOW()
           WHERE id = %s""",
        (error_message, chunk_id)
    )


def clear_chunk_error(cursor, chunk_id):
    """Clear any previous embedding error for a chunk."""
    cursor.execute(
        """UPDATE customgpt_document_chunks 
           SET embedding_error = NULL, embedding_attempted_at = NOW()
           WHERE id = %s""",
        (chunk_id,)
    )


def calculate_checksum(chunks):
    """Calculate SHA256 checksum of all chunk texts."""
    hasher = hashlib.sha256()
    for chunk in chunks:
        hasher.update(str(chunk['id']).encode('utf-8'))
        hasher.update(chunk['text'].encode('utf-8'))
    return hasher.hexdigest()


def batch_embed_chunks(embeddings, chunks, batch_size, log_file, cursor):
    """
    Embed chunks in batches with error handling.
    Returns: (successful_chunks, failed_chunk_ids)
    """
    successful_chunks = []
    failed_chunk_ids = []
    total_batches = (len(chunks) + batch_size - 1) // batch_size
    
    for batch_num in range(total_batches):
        start_idx = batch_num * batch_size
        end_idx = min(start_idx + batch_size, len(chunks))
        batch = chunks[start_idx:end_idx]
        
        log(log_file, f"  Processing batch {batch_num + 1}/{total_batches} ({len(batch)} chunks)")
        
        # Try to embed the entire batch
        try:
            texts = [chunk['text'] for chunk in batch]
            embeddings.embed_documents(texts)  # Test if batch works
            
            # If successful, clear errors and add to successful list
            for chunk in batch:
                clear_chunk_error(cursor, chunk['id'])
                successful_chunks.append(chunk)
            
            log(log_file, f"    ✓ Successfully embedded {len(batch)} chunks")
            
        except Exception as batch_error:
            log(log_file, f"    ⚠ Batch failed, trying individual chunks: {str(batch_error)[:100]}")
            
            # If batch fails, try each chunk individually
            for chunk in batch:
                try:
                    embeddings.embed_documents([chunk['text']])
                    clear_chunk_error(cursor, chunk['id'])
                    successful_chunks.append(chunk)
                except Exception as chunk_error:
                    error_msg = str(chunk_error)[:500]  # Truncate long errors
                    log(log_file, f"      ✗ Failed chunk {chunk['id']}: {error_msg[:100]}")
                    mark_chunk_error(cursor, chunk['id'], error_msg)
                    failed_chunk_ids.append(chunk['id'])
    
    return successful_chunks, failed_chunk_ids


def serialize_faiss_index(vectorstore):
    """Serialize FAISS vectorstore to bytes and JSON."""
    # Get the FAISS index bytes using file-based approach
    # (serialize_index doesn't work reliably, returns only 29 bytes)
    with tempfile.NamedTemporaryFile(delete=False) as tmp_file:
        tmp_path = tmp_file.name
    
    try:
        # Write index to temporary file
        faiss.write_index(vectorstore.index, tmp_path)
        
        # Read the bytes back
        with open(tmp_path, 'rb') as f:
            faiss_bytes = f.read()
    finally:
        # Clean up temporary file
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)
    
    # Get the index to docstore mapping
    id_map = {str(k): str(v) for k, v in vectorstore.index_to_docstore_id.items()}
    id_map_json = json.dumps(id_map)
    
    # Get the docstore (documents with metadata)
    docstore_dict = {}
    for doc_id, doc in vectorstore.docstore._dict.items():
        docstore_dict[str(doc_id)] = {
            'page_content': doc.page_content,
            'metadata': doc.metadata
        }
    docstore_json = json.dumps(docstore_dict)
    
    return faiss_bytes, id_map_json, docstore_json


def upsert_embeddings(cursor, customgpt_id, embed_model, faiss_bytes, 
                      id_map_json, docstore_json, checksum):
    """Upsert vector embeddings into database."""
    # Get dimension from the FAISS index
    # Write to temp file to read index (since faiss_bytes is from file format)
    with tempfile.NamedTemporaryFile(delete=False) as tmp_file:
        tmp_path = tmp_file.name
        tmp_file.write(faiss_bytes)
    
    try:
        temp_index = faiss.read_index(tmp_path)
        dim = temp_index.d
    finally:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)
    
    cursor.execute(
        """INSERT INTO customgpt_vector_embeddings 
           (customgpt_id, dim, embed_model, faiss_bytes, id_map_json, docstore_json, checksum)
           VALUES (%s, %s, %s, %s, %s, %s, %s)
           ON DUPLICATE KEY UPDATE
           dim = VALUES(dim),
           embed_model = VALUES(embed_model),
           faiss_bytes = VALUES(faiss_bytes),
           id_map_json = VALUES(id_map_json),
           docstore_json = VALUES(docstore_json),
           checksum = VALUES(checksum),
           updated_at = CURRENT_TIMESTAMP""",
        (customgpt_id, dim, embed_model, faiss_bytes, id_map_json, docstore_json, checksum)
    )


def main():
    """Main execution function."""
    if len(sys.argv) < 3:
        print("Usage: python generate_embeddings.py <customgpt_id> <log_file>")
        sys.exit(1)
    
    customgpt_id = int(sys.argv[1])
    log_file = sys.argv[2]
    
    # Initialize log file
    with open(log_file, 'w') as f:
        f.write(f"Vector Embeddings Generation Log - CustomGPT ID: {customgpt_id}\n")
        f.write("=" * 60 + "\n\n")
    
    log(log_file, f"Starting vector embeddings generation for CustomGPT ID: {customgpt_id}")
    
    try:
        # Get OpenAI configuration
        api_key, embed_model = get_openai_config()
        log(log_file, f"Using embedding model: {embed_model}")
        
        # Connect to database
        connection = get_db_connection()
        log(log_file, "Database connection established")
        
        with connection.cursor() as cursor:
            # Load all chunks
            chunks = load_chunks(cursor, customgpt_id)
            
            if not chunks:
                log(log_file, "No chunks found for this CustomGPT")
                log(log_file, "Please run generate_chunks.py first to create chunks")
                return
            
            log(log_file, f"Found {len(chunks)} chunk(s) to embed")
            log(log_file, "")
            
            # Calculate checksum
            checksum = calculate_checksum(chunks)
            log(log_file, f"Chunk checksum: {checksum}")
            log(log_file, "")
            
            # Initialize OpenAI embeddings
            embeddings = OpenAIEmbeddings(
                openai_api_key=api_key,
                model=embed_model
            )
            
            # Embed chunks in batches with error handling
            log(log_file, "Embedding chunks...")
            successful_chunks, failed_chunk_ids = batch_embed_chunks(
                embeddings, chunks, batch_size=100, log_file=log_file, cursor=cursor
            )
            
            if not successful_chunks:
                log(log_file, "")
                log(log_file, "✗ All chunks failed to embed. Cannot create vector store.")
                connection.commit()
                return
            
            log(log_file, "")
            log(log_file, f"Successfully embedded {len(successful_chunks)}/{len(chunks)} chunks")
            if failed_chunk_ids:
                log(log_file, f"Failed chunks: {len(failed_chunk_ids)}")
            log(log_file, "")
            
            # Build FAISS vector store
            log(log_file, "Building FAISS vector store...")
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
            log(log_file, f"✓ FAISS index created with {vectorstore.index.ntotal} vectors")
            log(log_file, "")
            
            # Serialize the vector store
            log(log_file, "Serializing vector store...")
            faiss_bytes, id_map_json, docstore_json = serialize_faiss_index(vectorstore)
            log(log_file, f"  FAISS index size: {len(faiss_bytes):,} bytes")
            log(log_file, f"  Index mapping: {len(json.loads(id_map_json))} entries")
            log(log_file, f"  Document store: {len(json.loads(docstore_json))} documents")
            log(log_file, "")
            
            # Save to database
            log(log_file, "Saving to database...")
            upsert_embeddings(
                cursor, customgpt_id, embed_model, faiss_bytes,
                id_map_json, docstore_json, checksum
            )
            
            # Commit all changes
            connection.commit()
            log(log_file, "✓ All changes committed to database")
            
        connection.close()
        log(log_file, "")
        log(log_file, "=" * 60)
        log(log_file, "✓ Vector embeddings generation completed!")
        log(log_file, f"  Total chunks: {len(chunks)}")
        log(log_file, f"  Successfully embedded: {len(successful_chunks)}")
        log(log_file, f"  Failed: {len(failed_chunk_ids)}")
        
    except Exception as e:
        log(log_file, "")
        log(log_file, "=" * 60)
        log(log_file, f"✗ ERROR: {str(e)}")
        import traceback
        log(log_file, traceback.format_exc())
        sys.exit(1)


if __name__ == '__main__':
    main()
