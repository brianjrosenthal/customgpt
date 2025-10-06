# Scripts Directory

## Python Chunk Generation Script

### Overview
The `generate_chunks.py` script processes documents stored in the database and generates text chunks using LangChain's RecursiveCharacterTextSplitter. These chunks are stored in the database for retrieval-augmented generation (RAG) applications.

### Installation

1. **Install Python dependencies:**
   ```bash
   cd scripts
   pip install -r requirements.txt
   ```

2. **Configure database access:**
   The script automatically reads database credentials from `../config.local.php`. Alternatively, you can set environment variables:
   ```bash
   export DB_HOST=localhost
   export DB_NAME=customgpt
   export DB_USER=root
   export DB_PASS=your_password
   ```

### Usage

#### Via Web Interface
1. Navigate to a Custom GPT edit page
2. Click "Actions" dropdown in the top right
3. Select "Generate Chunks from Files"
4. Monitor the progress in real-time

#### Via Command Line (for testing)
```bash
python3 generate_chunks.py <customgpt_id> <log_file_path>
```

Example:
```bash
python3 generate_chunks.py 1 /tmp/test_chunks.log
```

### Features

- **Automatic file type detection**: Supports TXT, MD, CSV, JSON, PDF, and DOCX files
- **Smart text splitting**: Uses LangChain's RecursiveCharacterTextSplitter with:
  - Chunk size: 1000 characters
  - Chunk overlap: 200 characters
  - Intelligent separators (paragraphs, sentences, spaces)
- **Progress logging**: Real-time progress updates to log file
- **Error handling**: Robust error handling with detailed error messages
- **Database integration**: Direct database access for reading files and storing chunks

### Security

- Log files use session-based tokens to prevent unauthorized access
- File paths are validated to prevent directory traversal attacks
- Admin-only access enforced at the PHP level
- Session tokens expire after process completion

### Supported File Types

- **Text files**: .txt, .md, .csv, .json
- **PDF files**: .pdf (requires pypdf)
- **Word documents**: .docx (requires python-docx)

### Troubleshooting

**ImportError: No module named 'langchain'**
- Run: `pip install -r requirements.txt`

**Database connection errors**
- Verify `config.local.php` has correct database credentials
- Ensure MySQL server is running
- Check that the user has appropriate permissions

**PDF processing errors**
- Ensure pypdf is installed: `pip install pypdf`
- Some PDF files may have extraction issues due to formatting

**Permission denied errors**
