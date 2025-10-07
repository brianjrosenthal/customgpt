#!/bin/bash
# Startup script for CustomGPT FastAPI Processing Service

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Change to script directory
cd "$SCRIPT_DIR"

# Check if virtual environment exists
if [ ! -d "venv" ]; then
    echo "Virtual environment not found. Creating one..."
    python3 -m venv venv
    echo "Installing dependencies..."
    source venv/bin/activate
    pip install -r requirements.txt
else
    source venv/bin/activate
fi

# Check if fastapi_server.py exists
if [ ! -f "fastapi_server.py" ]; then
    echo "Error: fastapi_server.py not found in $SCRIPT_DIR"
    exit 1
fi

# Check if config.local.php exists
if [ ! -f "$PROJECT_DIR/config.local.php" ]; then
    echo "Error: config.local.php not found in $PROJECT_DIR"
    echo "Please copy config.local.php.example to config.local.php and configure it."
    exit 1
fi

echo "Starting CustomGPT FastAPI Processing Service..."
echo "Press Ctrl+C to stop the service"
echo ""

# Run the FastAPI server
python fastapi_server.py
