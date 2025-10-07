#!/usr/bin/env python3
"""
FAISS Helper - Restore and cache vector stores for CustomGPTs.
Provides functions to restore FAISS vector stores from MySQL with disk caching.
"""

import os
import json
import io
import pymysql
import numpy as np
import tempfile
from pathlib import Path
from python_config import DB_CONFIG

try:
    from langchain_openai import OpenAIEmbeddings
    from langchain_community.vectorstores import FAISS
    from langchain.docstore.document import Document
    from langchain_community.docstore.in_memory import InMemoryDocstore
    import faiss
except ImportError as e:
    print(f"Error importing required libraries: {e}")
    print("Please install: pip install langchain-openai langchain-community faiss-cpu")
    raise


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
        except Exception:
            pass
    
    if not api_key:
        raise ValueError("OPENAI_API_KEY not found in environment or config.local.php")
    
    return api_key, embed_model


def get_cache_directory(customgpt_id):
    """Get the cache directory path for a CustomGPT."""
    cache_dir = Path(__file__).parent.parent / 'data' / 'faiss_index' / str(customgpt_id)
    cache_dir.mkdir(parents=True, exist_ok=True)
    return cache_dir


def get_cached_vectorstore(customgpt_id, checksum, embeddings):
    """
    Try to load vector store from disk cache.
    Returns: (vectorstore, True) if found, (None, False) otherwise
    """
    cache_dir = get_cache_directory(customgpt_id)
    
    # Look for cache files with matching checksum
    index_file = cache_dir / f'faiss_index_{checksum}.bin'
    id_map_file = cache_dir / f'id_map_{checksum}.json'
    docstore_file = cache_dir / f'docstore_{checksum}.json'
    
    if not all([index_file.exists(), id_map_file.exists(), docstore_file.exists()]):
        return None, False
    
    try:
        # Load FAISS index from file (using read_index for files)
        index = faiss.read_index(str(index_file))
        
        # Load id_map
        with open(id_map_file, 'r') as f:
            id_map = json.load(f)
        index_to_docstore_id = {int(k): v for k, v in id_map.items()}
        
        # Load docstore
        with open(docstore_file, 'r') as f:
            docstore_data = json.load(f)
        
        # Reconstruct docstore
        docstore_dict = {}
        for doc_id, doc_data in docstore_data.items():
            docstore_dict[doc_id] = Document(
                page_content=doc_data['page_content'],
                metadata=doc_data['metadata']
            )
        docstore = InMemoryDocstore(docstore_dict)
        
        # Create vectorstore
        vectorstore = FAISS(
            embedding_function=embeddings.embed_query,
            index=index,
            docstore=docstore,
            index_to_docstore_id=index_to_docstore_id
        )
        
        return vectorstore, True
    except Exception as e:
        print(f"Error loading from cache: {e}")
        return None, False


def save_to_cache(customgpt_id, checksum, vectorstore):
    """Save vector store to disk cache."""
    cache_dir = get_cache_directory(customgpt_id)
    
    # Clean up old cache files for this customgpt_id
    for old_file in cache_dir.glob('*'):
        if not old_file.name.endswith(f'_{checksum}.bin') and \
           not old_file.name.endswith(f'_{checksum}.json'):
            try:
                old_file.unlink()
            except Exception:
                pass
    
    try:
        # Save FAISS index
        index_file = cache_dir / f'faiss_index_{checksum}.bin'
        faiss.write_index(vectorstore.index, str(index_file))
        
        # Save id_map
        id_map_file = cache_dir / f'id_map_{checksum}.json'
        id_map = {str(k): str(v) for k, v in vectorstore.index_to_docstore_id.items()}
        with open(id_map_file, 'w') as f:
            json.dump(id_map, f)
        
        # Save docstore
        docstore_file = cache_dir / f'docstore_{checksum}.json'
        docstore_data = {}
        for doc_id, doc in vectorstore.docstore._dict.items():
            docstore_data[str(doc_id)] = {
                'page_content': doc.page_content,
                'metadata': doc.metadata
            }
        with open(docstore_file, 'w') as f:
            json.dump(docstore_data, f)
        
        return True
    except Exception as e:
        print(f"Error saving to cache: {e}")
        return False


def restore_from_database(customgpt_id, embeddings):
    """
    Restore vector store from MySQL database.
    Returns: (vectorstore, checksum) or (None, None) if not found
    """
    connection = get_db_connection()
    
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                """SELECT faiss_bytes, id_map_json, docstore_json, checksum
                   FROM customgpt_vector_embeddings
                   WHERE customgpt_id = %s""",
                (customgpt_id,)
            )
            row = cursor.fetchone()
            
            if not row:
                return None, None
            
            # Deserialize FAISS index from bytes (file format)
            # Write to temp file and read back as index
            with tempfile.NamedTemporaryFile(delete=False) as tmp_file:
                tmp_path = tmp_file.name
                tmp_file.write(row['faiss_bytes'])
            
            try:
                index = faiss.read_index(tmp_path)
            finally:
                if os.path.exists(tmp_path):
                    os.unlink(tmp_path)
            
            # Deserialize id_map
            id_map = json.loads(row['id_map_json'])
            index_to_docstore_id = {int(k): v for k, v in id_map.items()}
            
            # Deserialize docstore
            docstore_data = json.loads(row['docstore_json'])
            docstore_dict = {}
            for doc_id, doc_data in docstore_data.items():
                docstore_dict[doc_id] = Document(
                    page_content=doc_data['page_content'],
                    metadata=doc_data['metadata']
                )
            docstore = InMemoryDocstore(docstore_dict)
            
            # Create vectorstore
            vectorstore = FAISS(
                embedding_function=embeddings.embed_query,
                index=index,
                docstore=docstore,
                index_to_docstore_id=index_to_docstore_id
            )
            
            checksum = row['checksum']
            return vectorstore, checksum
    
    finally:
        connection.close()


def restore_vector_store(customgpt_id, k=4):
    """
    Restore a FAISS vector store for a CustomGPT with disk caching.
    
    Args:
        customgpt_id: The CustomGPT ID
        k: Number of documents to retrieve (default: 4)
    
    Returns:
        A LangChain retriever, or None if no vector store exists
    """
    # Get OpenAI embeddings
    api_key, embed_model = get_openai_config()
    embeddings = OpenAIEmbeddings(
        openai_api_key=api_key,
        model=embed_model
    )
    
    # First, try to get checksum from database
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT checksum FROM customgpt_vector_embeddings WHERE customgpt_id = %s",
                (customgpt_id,)
            )
            row = cursor.fetchone()
            if not row:
                print(f"No vector embeddings found for CustomGPT {customgpt_id}")
                return None
            checksum = row['checksum']
    finally:
        connection.close()
    
    # Try to load from cache
    vectorstore, from_cache = get_cached_vectorstore(customgpt_id, checksum, embeddings)
    
    if from_cache:
        print(f"Loaded vector store from cache for CustomGPT {customgpt_id}")
    else:
        # Restore from database
        print(f"Restoring vector store from database for CustomGPT {customgpt_id}...")
        vectorstore, checksum = restore_from_database(customgpt_id, embeddings)
        
        if vectorstore:
            # Save to cache for next time
            print(f"Saving to cache...")
            save_to_cache(customgpt_id, checksum, vectorstore)
            print(f"Vector store restored and cached")
        else:
            print(f"No vector embeddings found for CustomGPT {customgpt_id}")
            return None
    
    # Return as retriever
    return vectorstore.as_retriever(search_kwargs={"k": k})


def clear_cache(customgpt_id=None):
    """
    Clear disk cache for a specific CustomGPT or all CustomGPTs.
    
    Args:
        customgpt_id: The CustomGPT ID to clear, or None to clear all
    """
    if customgpt_id is not None:
        cache_dir = get_cache_directory(customgpt_id)
        if cache_dir.exists():
            for file in cache_dir.glob('*'):
                file.unlink()
            print(f"Cleared cache for CustomGPT {customgpt_id}")
    else:
        base_cache_dir = Path(__file__).parent.parent / 'data' / 'faiss_index'
        if base_cache_dir.exists():
            import shutil
            shutil.rmtree(base_cache_dir)
            print("Cleared all FAISS cache")


# Example usage
if __name__ == '__main__':
    import sys
    
    if len(sys.argv) < 2:
        print("Usage: python faiss_helper.py <customgpt_id> [query]")
        print("   or: python faiss_helper.py --clear [customgpt_id]")
        sys.exit(1)
    
    if sys.argv[1] == '--clear':
        customgpt_id = int(sys.argv[2]) if len(sys.argv) > 2 else None
        clear_cache(customgpt_id)
    else:
        customgpt_id = int(sys.argv[1])
        query = sys.argv[2] if len(sys.argv) > 2 else "stadium capacity"
        
        print(f"Testing vector store restore for CustomGPT {customgpt_id}")
        print(f"Query: {query}")
        print()
        
        retriever = restore_vector_store(customgpt_id, k=3)
        
        if retriever:
            print(f"\nSearching for: {query}")
            results = retriever.get_relevant_documents(query)
            
            print(f"\nFound {len(results)} relevant chunks:")
            for i, doc in enumerate(results, 1):
                print(f"\n{i}. Chunk ID: {doc.metadata.get('chunk_id')}")
                print(f"   File: {doc.metadata.get('filename')}")
                print(f"   Content: {doc.page_content[:200]}...")
        else:
            print("No vector store available")
