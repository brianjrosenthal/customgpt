# FastAPI Migration Guide

This document describes the changes made to migrate from direct Python script execution to the FastAPI-based processing service.

## What Changed

### Architecture Change

**Before:**
- PHP scripts used `exec()` to spawn new Python processes for each task
- Each task ran as an independent process
- No concurrency control
- Process management was manual

**After:**
- PHP scripts make HTTP API calls to a persistent FastAPI service
- Single Python process handles multiple tasks
- Built-in concurrency control (configurable, default: 2)
- Automatic fallback to original method if service unavailable

## Modified Files

### Python Files

1. **`scripts/fastapi_server.py`** (NEW)
   - Main FastAPI application
   - Job queue and management
   - Background task processing
   - API endpoints for job creation and monitoring

2. **`scripts/requirements.txt`** (MODIFIED)
   - Added FastAPI dependencies:
     - `fastapi>=0.104.0`
     - `uvicorn[standard]>=0.24.0`
     - `pydantic>=2.0.0`

3. **`scripts/start_fastapi.sh`** (NEW)
   - Startup script for the FastAPI service
   - Handles virtual environment setup
   - Automatic dependency installation

### PHP Files

1. **`customgpts/generate_chunks_eval.php`** (MODIFIED)
   - Now checks if FastAPI service is available
   - Makes HTTP POST request to FastAPI API if available
   - Falls back to direct script execution if service unavailable
   - Stores job ID in session for progress tracking

2. **`customgpts/generate_embeddings_eval.php`** (MODIFIED)
   - Same changes as `generate_chunks_eval.php`
   - HTTP API integration with fallback

### Configuration Files

1. **`config.local.php`** (MODIFIED)
   - Added three new configuration constants:
     ```php
     define('FASTAPI_HOST', 'localhost');
     define('FASTAPI_PORT', 8001);
     define('FASTAPI_MAX_CONCURRENT_JOBS', 2);
     ```

2. **`config.local.php.example`** (MODIFIED)
   - Added same configuration with defaults

### Documentation

1. **`scripts/FASTAPI_README.md`** (NEW)
   - Comprehensive documentation of FastAPI service
   - API endpoint reference
   - Configuration guide
   - Troubleshooting section

2. **`scripts/FASTAPI_MIGRATION_GUIDE.md`** (NEW - THIS FILE)
   - Migration documentation
   - Change summary

## How to Migrate

### Step 1: Update Configuration

Add the FastAPI configuration to your `config.local.php`:

```php
// FastAPI Service Configuration
define('FASTAPI_HOST', 'localhost');
define('FASTAPI_PORT', 8001);
define('FASTAPI_MAX_CONCURRENT_JOBS', 2);
```

### Step 2: Install Dependencies

The startup script will handle this, but you can do it manually:

```bash
cd scripts
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```

### Step 3: Start the FastAPI Service

```bash
cd scripts
./start_fastapi.sh
```

Or run in background:

```bash
cd scripts
nohup ./start_fastapi.sh > fastapi.log 2>&1 &
```

### Step 4: Verify Service

```bash
curl http://localhost:8001/api/v1/health
```

Expected response:
```json
{"status":"healthy","timestamp":"...","max_concurrent_jobs":2}
```

### Step 5: Test the System

1. Log into your CustomGPT application
2. Navigate to a CustomGPT
3. Try "Generate Chunks from Files"
4. Try "Generate Vector Embeddings"

Both should now use the FastAPI service automatically.

## Backward Compatibility

### Automatic Fallback

The system is designed with backward compatibility in mind:

- **If FastAPI service is running:** Uses HTTP API (new method)
- **If FastAPI service is not running:** Falls back to direct script execution (old method)

This means:
- ✅ Existing functionality is preserved
- ✅ No downtime required for migration
- ✅ System works even if FastAPI service fails
- ✅ Can gradually adopt the new system

### No Database Changes

The migration does not require any database changes. All database operations remain the same.

### No Breaking Changes

- PHP evaluation scripts maintain their original API
- Progress tracking pages continue to work
- Log files are still created in `/tmp/`
- Session variables maintain compatibility

## Session Variable Changes

### Before
```php
$_SESSION['chunk_generation_token'] = $token;
$_SESSION['embedding_generation_token'] = $token;
```

### After (when using FastAPI)
```php
$_SESSION['chunk_generation_job_id'] = $job_id;  // UUID from FastAPI
$_SESSION['embedding_generation_job_id'] = $job_id;  // UUID from FastAPI
```

### After (when using fallback)
```php
$_SESSION['chunk_generation_token'] = $token;  // Original token-based approach
$_SESSION['embedding_generation_token'] = $token;  // Original token-based approach
```

**Note:** Progress tracking pages may need updates to handle both job ID and token formats.

## Benefits of Migration

### Performance

- **Single Process**: One Python process vs. multiple spawned processes
- **Lower Overhead**: No process startup time for each task
- **Better Resource Usage**: Shared memory and connections

### Concurrency Control

- **Configurable Limits**: Set max concurrent jobs (default: 2)
- **Prevents Overload**: System won't spawn unlimited processes
- **Queue Management**: Jobs wait their turn automatically

### Monitoring & Management

- **Real-time Status**: Check job status via API
- **Centralized Logs**: All jobs logged in one place
- **Health Checks**: Monitor service health
- **Job History**: Track completed and failed jobs

### Scalability

- **Easy to Scale**: Increase concurrent jobs in config
- **Future-Ready**: Can add multiple worker processes
- **Better for Production**: More suitable for production deployments

## Testing Checklist

After migration, verify:

- [ ] FastAPI service starts successfully
- [ ] Health check endpoint responds
- [ ] Chunk generation works (FastAPI mode)
- [ ] Embedding generation works (FastAPI mode)
- [ ] Progress tracking displays correctly
- [ ] Error handling works properly
- [ ] Fallback works when service is stopped
- [ ] Concurrent job limiting works as expected
- [ ] Log files are created correctly

## Rollback Plan

If issues occur, you can rollback by:

1. **Stop the FastAPI service**
   ```bash
   # Find the process
   ps aux | grep fastapi_server
   # Kill it
   kill <PID>
   ```

2. **System automatically falls back** to direct script execution

No other changes needed - the PHP scripts detect when FastAPI is unavailable and use the original method.

## Common Issues

### Port Already in Use

If port 8001 is in use:
```bash
# Find what's using the port
lsof -i :8001
# Either kill that process or change FASTAPI_PORT in config.local.php
```

### Import Errors

If you see import errors when starting FastAPI:
```bash
cd scripts
source venv/bin/activate
pip install -r requirements.txt
```

### Health Check Fails

If health check times out:
1. Verify service is running
2. Check firewall settings
3. Try `curl http://127.0.0.1:8001/api/v1/health` instead of localhost

## Production Deployment

For production environments, consider:

1. **Use systemd or supervisor** for automatic restart
2. **Monitor the service** with tools like Prometheus
3. **Set appropriate concurrent job limits** based on server capacity
4. **Implement log rotation** for FastAPI logs
5. **Use a process manager** like supervisord
6. **Configure firewall rules** if needed

See `scripts/FASTAPI_README.md` for systemd example.

## Support

For questions or issues:
1. Check `scripts/FASTAPI_README.md` for detailed documentation
2. Review FastAPI service logs
3. Test with service stopped to verify fallback works
4. Check PHP error logs for API call failures

## Timeline

- **2025-10-07**: Initial FastAPI implementation completed
  - FastAPI service created
  - PHP integration added
  - Documentation written
  - System ready for testing
