# FastAPI Processing Service

This document describes the FastAPI-based background processing service for CustomGPT document chunking and embedding generation.

## Overview

The FastAPI service provides a centralized, efficient way to handle background processing tasks with built-in concurrency control and job management. It replaces the previous approach of spawning individual Python processes for each task.

## Architecture

### Components

1. **FastAPI Server** (`fastapi_server.py`)
   - RESTful API server running on configurable port (default: 8001)
   - Background task queue with concurrent job limiting
   - Job status tracking and logging
   - Health check endpoints

2. **PHP Integration** (`generate_chunks_eval.php`, `generate_embeddings_eval.php`)
   - HTTP API calls to FastAPI service
   - Automatic fallback to direct script execution if service unavailable
   - Session-based job tracking

3. **Startup Script** (`start_fastapi.sh`)
   - Automated service startup
   - Virtual environment management
   - Dependency installation

## Configuration

All FastAPI settings are configured in `config.local.php`:

```php
// FastAPI Service Configuration
define('FASTAPI_HOST', 'localhost');
define('FASTAPI_PORT', 8001);  // Port for the FastAPI processing service
define('FASTAPI_MAX_CONCURRENT_JOBS', 2);  // Maximum concurrent jobs (default: 2)
```

### Configuration Options

- **FASTAPI_HOST**: Hostname where the FastAPI service runs (default: `localhost`)
- **FASTAPI_PORT**: Port number for the service (default: `8001`)
- **FASTAPI_MAX_CONCURRENT_JOBS**: Maximum number of concurrent jobs (default: `2`)

## Installation

### 1. Install Dependencies

The startup script will automatically create a virtual environment and install dependencies. However, you can do this manually:

```bash
cd scripts
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```

### 2. Configure Settings

Ensure your `config.local.php` includes the FastAPI configuration settings (see Configuration section above).

### 3. Verify Python Path

Make sure the `PYTHON_PATH` in `config.local.php` points to a Python installation with all required packages.

## Usage

### Starting the Service

#### Manual Startup

```bash
cd scripts
./start_fastapi.sh
```

Or directly:

```bash
cd scripts
source venv/bin/activate
python fastapi_server.py
```

The service will start and display:
```
Starting CustomGPT Processing Service on port 8001
Max concurrent jobs: 2
INFO:     Started server process [12345]
INFO:     Waiting for application startup.
INFO:     Application startup complete.
INFO:     Uvicorn running on http://0.0.0.0:8001
```

#### Background Startup

To run the service in the background:

```bash
cd scripts
nohup ./start_fastapi.sh > fastapi.log 2>&1 &
```

### Stopping the Service

If running in foreground: Press `Ctrl+C`

If running in background:
```bash
# Find the process ID
ps aux | grep fastapi_server

# Kill the process
kill <PID>
```

### Service Status

Check if the service is running:

```bash
curl http://localhost:8001/api/v1/health
```

Expected response:
```json
{
  "status": "healthy",
  "timestamp": "2025-10-07T07:00:00.000000",
  "max_concurrent_jobs": 2
}
```

## API Endpoints

### Health Check

**GET** `/api/v1/health`

Returns service health status.

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2025-10-07T07:00:00.000000",
  "max_concurrent_jobs": 2
}
```

### Service Status

**GET** `/api/v1/status`

Returns detailed service status including job counts.

**Response:**
```json
{
  "status": "running",
  "max_concurrent_jobs": 2,
  "active_jobs": 1,
  "completed_jobs": 5,
  "failed_jobs": 0,
  "total_jobs": 6
}
```

### Generate Chunks

**POST** `/api/v1/chunks/generate/{customgpt_id}`

Starts chunk generation for a CustomGPT.

**Parameters:**
- `customgpt_id` (path): The ID of the CustomGPT

**Response:**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "queued",
  "message": "Chunk generation job created for CustomGPT 1"
}
```

### Generate Embeddings

**POST** `/api/v1/embeddings/generate/{customgpt_id}`

Starts embedding generation for a CustomGPT.

**Parameters:**
- `customgpt_id` (path): The ID of the CustomGPT

**Response:**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440001",
  "status": "queued",
  "message": "Embedding generation job created for CustomGPT 1"
}
```

### Job Status

**GET** `/api/v1/jobs/{job_id}/status`

Returns the status of a specific job.

**Parameters:**
- `job_id` (path): The UUID of the job

**Response:**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "running",
  "customgpt_id": 1,
  "task_type": "chunks",
  "started_at": "2025-10-07T07:00:00.000000",
  "completed_at": null,
  "error": null,
  "progress": "5/10 documents processed"
}
```

**Status Values:**
- `queued`: Job is waiting to start
- `running`: Job is currently executing
- `completed`: Job finished successfully
- `failed`: Job encountered an error

### Job Logs

**GET** `/api/v1/jobs/{job_id}/logs`

Returns the logs for a specific job.

**Parameters:**
- `job_id` (path): The UUID of the job

**Response:**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "logs": [
    "[2025-10-07 07:00:00] Starting chunk generation for CustomGPT ID: 1",
    "[2025-10-07 07:00:01] Database connection established",
    "[2025-10-07 07:00:01] Found 10 document(s) to process"
  ],
  "log_file": "/tmp/chunk_generation_1_550e8400-e29b-41d4-a716-446655440000.log",
  "file_content": "..."
}
```

## How It Works

### Job Processing Flow

1. **PHP Evaluation Script** (`generate_chunks_eval.php` or `generate_embeddings_eval.php`)
   - Checks if FastAPI service is available via health check
   - If available: Makes HTTP POST request to create job
   - If unavailable: Falls back to direct script execution

2. **FastAPI Service**
   - Receives job request
   - Creates unique job ID
   - Adds job to background task queue
   - Returns job ID to PHP
   - Processes job asynchronously with concurrency control

3. **Background Processing**
   - Job acquires semaphore slot (respects `MAX_CONCURRENT_JOBS`)
   - Processes chunks/embeddings
   - Updates job status and logs
   - Releases semaphore slot when complete

4. **Progress Tracking**
   - PHP stores job ID in session
   - Progress page polls job status via API
   - Displays real-time progress and logs

### Concurrency Control

The service uses an asyncio semaphore to limit concurrent jobs:

```python
active_jobs_semaphore = asyncio.Semaphore(MAX_CONCURRENT_JOBS)
```

When a job starts:
- It acquires a semaphore slot
- If all slots are full, it waits until one becomes available
- Ensures system resources are not overwhelmed

### Fallback Mechanism

If the FastAPI service is not running:
- PHP scripts automatically detect this via health check timeout
- System falls back to original direct script execution
- No functionality is lost
- Log files indicate fallback was used

## Benefits

### vs. Direct Script Execution

| Feature | FastAPI Service | Direct Execution |
|---------|----------------|------------------|
| Resource Management | Single Python process | New process per task |
| Concurrency Control | Built-in, configurable | Manual/none |
| Job Tracking | Real-time via API | File-based logs only |
| Scalability | High | Limited |
| Process Overhead | Low | High |
| Monitoring | Centralized | Distributed |

### Key Advantages

1. **Efficient Resource Usage**: Single Python process handles all tasks
2. **Better Control**: Configurable concurrency limits prevent system overload
3. **Improved Monitoring**: Real-time job status and centralized logging
4. **Scalability**: Easy to add more worker processes if needed
5. **Maintainability**: Cleaner separation of concerns
6. **Reliability**: Automatic fallback ensures continuous operation

## Troubleshooting

### Service Won't Start

**Problem**: FastAPI service fails to start

**Solutions**:
1. Check if port 8001 is already in use:
   ```bash
   lsof -i :8001
   ```
2. Verify Python dependencies are installed:
   ```bash
   cd scripts
   source venv/bin/activate
   pip list | grep fastapi
   ```
3. Check config.local.php exists and has correct settings

### Jobs Not Processing

**Problem**: Jobs stay in "queued" status

**Solutions**:
1. Check service logs for errors
2. Verify database connection in `python_config.py`
3. Ensure OpenAI API key is configured correctly
4. Check system resources (CPU, memory)

### Fallback Always Used

**Problem**: System always falls back to direct script execution

**Solutions**:
1. Verify FastAPI service is running:
   ```bash
   curl http://localhost:8001/api/v1/health
   ```
2. Check firewall settings
3. Verify FASTAPI_HOST and FASTAPI_PORT in config.local.php
4. Check service logs for startup errors

### High Memory Usage

**Problem**: FastAPI service consuming too much memory

**Solutions**:
1. Reduce MAX_CONCURRENT_JOBS in config.local.php
2. Monitor job completion and clear old job data periodically
3. Consider implementing job history cleanup

## Future Enhancements

### Planned Features

1. **Automatic Restart**: systemd or supervisor integration for auto-restart on failure
2. **Job History Cleanup**: Automatic removal of old completed jobs
3. **Enhanced Monitoring**: Prometheus metrics endpoint
4. **Multiple Workers**: Support for multiple worker processes
5. **Job Prioritization**: Priority queue for urgent tasks
6. **Webhook Notifications**: Callback URLs for job completion

### systemd Integration (Future)

Example systemd service file (`/etc/systemd/system/customgpt-fastapi.service`):

```ini
[Unit]
Description=CustomGPT FastAPI Processing Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/customgpt/scripts
ExecStart=/path/to/customgpt/scripts/venv/bin/python fastapi_server.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

## Support

For issues or questions:
1. Check this documentation
2. Review service logs
3. Verify configuration settings
4. Test with direct script execution as fallback

## Version History

- **1.0.0** (2025-10-07): Initial FastAPI implementation
  - Background job processing
  - Concurrency control
  - Health check endpoints
  - Automatic fallback mechanism
