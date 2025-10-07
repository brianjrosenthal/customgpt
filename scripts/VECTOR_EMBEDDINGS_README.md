# Vector Embeddings Generation - Testing Guide

This guide explains how to test the vector embeddings generation feature from the command line and through the web interface.

## Prerequisites

1. **Install Python Dependencies**
   ```bash
   cd scripts
   python3 -m pip install -r requirements.txt
   ```

2. **Run Database Migrations**
   ```bash
   # From the project root
   mysql -u root -p customgpt < db_migrations/2025-10-06_add_vector_embeddings_table.sql
   mysql -u root -p customgpt < db_migrations/2025-10-06_add_chunk_error_tracking.sql
   ```

3. **Configure OpenAI API Key**
   
   Add to your `config.local.php`:
   ```php
   // OpenAI API configuration for vector embeddings
   define('OPENAI_API_KEY', 'sk-your-actual-openai-api-key-here');
   define('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small');
   ```

4. **Ensure Chunks Exist**
   
   Before generating embeddings, you must first generate chunks:
   ```bash
   python3 generate_chunks.py <customgpt_id> /tmp/test_chunks.log
   ```

## Command Line Testing

### Test 1: Basic Embedding Generation

```bash
# Set environment variables (or use config.local.php)
export OPENAI_API_KEY="sk-your-key-here"
export OPENAI_EMBEDDING_MODEL="text-embedding-3-small"

# Generate embeddings for CustomGPT ID 1
python3 scripts/generate_embeddings.py 1 /tmp/test_embeddings.log

# Monitor the log file in real-time
tail -f /tmp/test_embeddings.log
```

**Expected Output:**
```
[2025-10-06 22:30:00] Starting vector embeddings generation for CustomGPT ID: 1
[2025-10-06 22:30:00] Using embedding model: text-embedding-3-small
[2025-10-06 22:30:00] Database connection established
[2025-10-06 22:30:00] Found 76 chunk(s) to embed
[2025-10-06 22:30:00] Chunk checksum: a1b2c3d4...
[2025-10-06 22:30:00] 
[2025-10-06 22:30:00] Embedding chunks...
[2025-10-06 22:30:01]   Processing batch 1/1 (76 chunks)
[2025-10-06 22:30:05]     ✓ Successfully embedded 76 chunks
[2025-10-06 22:30:05] 
[2025-10-06 22:30:05] Successfully embedded 76/76 chunks
[2025-10-06 22:30:05] 
[2025-10-06 22:30:05] Building FAISS vector store...
[2025-10-06 22:30:06] ✓ FAISS index created with 76 vectors
[2025-10-06 22:30:06] 
[2025-10-06 22:30:06] Serializing vector store...
[2025-10-06 22:30:06]   FAISS index size: 123,456 bytes
[2025-10-06 22:30:06]   Index mapping: 76 entries
[2025-10-06 22:30:06]   Document store: 76 documents
[2025-10-06 22:30:06] 
[2025-10-06 22:30:06] Saving to database...
[2025-10-06 22:30:06] ✓ All changes committed to database
[2025-10-06 22:30:06] 
[2025-10-06 22:30:06] ============================================================
[2025-10-06 22:30:06] ✓ Vector embeddings generation completed!
[2025-10-06 22:30:06]   Total chunks: 76
[2025-10-06 22:30:06]   Successfully embedded: 76
[2025-10-06 22:30:06]   Failed: 0
```

### Test 2: Restore and Query Vector Store

```bash
# Test the restore helper with a query
python3 scripts/faiss_helper.py 1 "stadium capacity"
```

**Expected Output:**
```
Testing vector store restore for CustomGPT 1
Query: stadium capacity

Loaded vector store from cache for CustomGPT 1

Searching for: stadium capacity

Found 3 relevant chunks:

1. Chunk ID: 12
   File: 2024_08_05_allegiant_las_vegas_nevada.txt
   Content: Allegiant Stadium has a seating capacity of 65,000 for football games and can expand to 72,000 for other events. The venue features a retractable natural turf field...

2. Chunk ID: 45
   File: 2025_09_14_SoFi_Stadium_Inglewood_CA.txt
   Content: SoFi Stadium opened in 2020 with a capacity of 70,240, expandable to 100,240 for major events. The stadium is the most expensive sports venue ever built...

3. Chunk ID: 67
   File: 2025_10_12_Rose_Bowl_Stadium_Pasadena_CA.txt
   Content: The Rose Bowl has hosted five NFL Super Bowl games and the 1994 FIFA World Cup Final. With a capacity of 92,542, it is one of the largest stadiums...
```

### Test 3: Clear Cache

```bash
# Clear cache for a specific CustomGPT
python3 scripts/faiss_helper.py --clear 1

# Or clear all caches
python3 scripts/faiss_helper.py --clear
```

### Test 4: Check for Embedding Errors

```bash
# Check if any chunks failed to embed
mysql -u root -p customgpt -e "
SELECT 
  COUNT(*) as total_chunks, 
  COUNT(embedding_error) as failed_chunks,
  COUNT(CASE WHEN embedding_error IS NULL AND embedding_attempted_at IS NOT NULL THEN 1 END) as successful_chunks
FROM customgpt_document_chunks c
JOIN customgpt_documents d ON c.customgpt_document_id = d.id 
WHERE d.customgpt_id = 1;
"
```

### Test 5: View Failed Chunks (if any)

```bash
# See details of chunks that failed to embed
mysql -u root -p customgpt -e "
SELECT c.id, c.text, c.embedding_error, c.embedding_attempted_at
FROM customgpt_document_chunks c
JOIN customgpt_documents d ON c.customgpt_document_id = d.id 
WHERE d.customgpt_id = 1 AND c.embedding_error IS NOT NULL;
"
```

## Web Interface Testing

### Test 1: Generate Embeddings via Web

1. **Login** as an admin user
2. **Navigate** to a CustomGPT edit page (e.g., http://localhost:8000/customgpts/edit.php?id=1)
3. **Click** the "Actions ▼" dropdown in the top right
4. **Select** "Generate Vector Embeddings"
5. **Watch** the real-time progress display
6. **Verify** completion message shows success

### Test 2: Verify in Database

```bash
# Check if embeddings were saved
mysql -u root -p customgpt -e "
SELECT 
  customgpt_id,
  dim,
  embed_model,
  LENGTH(faiss_bytes) as faiss_size,
  JSON_LENGTH(id_map_json) as num_vectors,
  checksum,
  updated_at
FROM customgpt_vector_embeddings
WHERE customgpt_id = 1;
"
```

### Test 3: Check Disk Cache

```bash
# List cached files
ls -lh data/faiss_index/1/

# Expected output:
# faiss_index_<checksum>.bin
# id_map_<checksum>.json
# docstore_<checksum>.json
```

## Error Scenarios to Test

### Scenario 1: Missing OpenAI API Key

```bash
# Unset the API key
unset OPENAI_API_KEY

# Try to run the script
python3 scripts/generate_embeddings.py 1 /tmp/test_error.log
```

**Expected:** Error message about missing API key

### Scenario 2: Invalid API Key

```bash
export OPENAI_API_KEY="sk-invalid-key"
python3 scripts/generate_embeddings.py 1 /tmp/test_invalid.log
```

**Expected:** OpenAI API authentication error, logged to file

### Scenario 3: No Chunks Available

```bash
# Try to generate embeddings for a CustomGPT with no chunks
python3 scripts/generate_embeddings.py 999 /tmp/test_no_chunks.log
```

**Expected:** Message saying "No chunks found for this CustomGPT"

### Scenario 4: Rate Limiting

If you hit OpenAI's rate limits, the script should:
- Log the rate limit error
- Mark affected chunks with embedding_error
- Continue processing other chunks
- Report statistics at the end

## Performance Benchmarks

Expected processing times (approximate):
- **10 chunks**: ~2-3 seconds
- **50 chunks**: ~8-10 seconds  
- **100 chunks**: ~15-20 seconds
- **500 chunks**: ~60-90 seconds

Factors affecting speed:
- OpenAI API response time
- Batch size (default: 100)
- Network latency
- Chunk text length

## Disk Cache Location

The FAISS indices are cached at:
```
./data/faiss_index/<customgpt_id>/
├── faiss_index_<checksum>.bin
├── id_map_<checksum>.json
└── docstore_<checksum>.json
```

Cache invalidation happens automatically when:
- Chunks are regenerated (new checksum)
- New cache is created (old files deleted)

## Troubleshooting

### Issue: "ModuleNotFoundError: No module named 'faiss'"

**Solution:**
```bash
python3 -m pip install faiss-cpu
```

### Issue: "No module named 'langchain_openai'"

**Solution:**
```bash
python3 -m pip install langchain-openai
```

### Issue: Embeddings generation is very slow

**Possible causes:**
- Large batch size causing timeouts
- Slow network connection to OpenAI
- Very long chunk texts

**Solution:** Edit `generate_embeddings.py` and reduce batch size from 100 to 50 or 25

### Issue: Cache not being used

**Check:**
1. Verify cache directory exists: `data/faiss_index/<id>/`
2. Check file permissions
3. Verify checksum matches between cache files and database

## Next Steps

After successful testing:
1. Use the restored vector store in your RAG queries
2. Integrate with LangChain's RetrievalQA chain
3. Build a query interface for users
4. Monitor embedding costs in OpenAI dashboard
5. Consider batch regeneration for multiple CustomGPTs

## Cost Estimation

OpenAI text-embedding-3-small pricing (as of 2024):
- **$0.00002 per 1K tokens**

For 100 chunks averaging 200 words (~300 tokens) each:
- Total tokens: 30,000
- Cost: ~$0.0006 (less than $0.001)

Very cost-effective for typical use cases!
