#!/usr/bin/env python3
"""
Generate document chunks using LangChain for CustomGPT documents.
This script processes all documents for a given CustomGPT ID and creates
text chunks that can be used for retrieval-augmented generation.
"""

import sys
import os
import pymysql
import io
from datetime import datetime
from python_config import DB_CONFIG

# LangChain imports
from langchain.text_splitter import RecursiveCharacterTextSplitter

# Document loaders
try:
    from pypdf import PdfReader
except ImportError:
    PdfReader = None

try:
    from docx import Document as DocxDocument
except ImportError:
    DocxDocument = None


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


def extract_text_from_pdf(data):
    """Extract text from PDF file data."""
    if PdfReader is None:
        raise ImportError("pypdf library is required to process PDF files. Install with: pip install pypdf")
    
    try:
        pdf_file = io.BytesIO(data)
        reader = PdfReader(pdf_file)
        text_parts = []
        for page in reader.pages:
            text_parts.append(page.extract_text())
        return '\n\n'.join(text_parts)
    except Exception as e:
        raise ValueError(f"Error extracting text from PDF: {str(e)}")


def extract_text_from_docx(data):
    """Extract text from DOCX file data."""
    if DocxDocument is None:
        raise ImportError("python-docx library is required to process DOCX files. Install with: pip install python-docx")
    
    try:
        docx_file = io.BytesIO(data)
        doc = DocxDocument(docx_file)
        text_parts = [paragraph.text for paragraph in doc.paragraphs]
        return '\n\n'.join(text_parts)
    except Exception as e:
        raise ValueError(f"Error extracting text from DOCX: {str(e)}")


def extract_text_from_file(data, content_type, filename):
    """Extract text from a file based on its content type."""
    content_type = (content_type or '').lower()
    filename = (filename or '').lower()
    
    # Handle text files
    if content_type.startswith('text/') or filename.endswith(('.txt', '.md', '.csv', '.json')):
        try:
            return data.decode('utf-8')
        except UnicodeDecodeError:
            try:
                return data.decode('latin-1')
            except Exception as e:
                raise ValueError(f"Error decoding text file: {str(e)}")
    
    # Handle PDF files
    if content_type == 'application/pdf' or filename.endswith('.pdf'):
        return extract_text_from_pdf(data)
    
    # Handle DOCX files
    if content_type in ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/msword'] or filename.endswith(('.docx', '.doc')):
        return extract_text_from_docx(data)
    
    raise ValueError(f"Unsupported file type: {content_type} (filename: {filename})")


def delete_chunks_for_document(cursor, document_id):
    """Delete all existing chunks for a document."""
    cursor.execute(
        "DELETE FROM customgpt_document_chunks WHERE customgpt_document_id = %s",
        (document_id,)
    )


def insert_chunks(cursor, document_id, chunks):
    """Insert chunks for a document with proper sort order."""
    for sort_order, chunk_text in enumerate(chunks):
        cursor.execute(
            """INSERT INTO customgpt_document_chunks 
               (customgpt_document_id, sort_order, text, created_at)
               VALUES (%s, %s, %s, NOW())""",
            (document_id, sort_order, chunk_text)
        )


def process_document(cursor, document_id, file_id, log_file):
    """Process a single document and generate chunks."""
    # Get file data
    cursor.execute(
        """SELECT data, content_type, original_filename 
           FROM secure_files WHERE id = %s""",
        (file_id,)
    )
    file_row = cursor.fetchone()
    
    if not file_row:
        log(log_file, f"  ERROR: File {file_id} not found in secure_files")
        return False
    
    filename = file_row['original_filename'] or 'unknown'
    content_type = file_row['content_type'] or ''
    data = file_row['data']
    
    log(log_file, f"  Processing: {filename} ({len(data)} bytes)")
    
    try:
        # Extract text from file
        text = extract_text_from_file(data, content_type, filename)
        log(log_file, f"  Extracted {len(text)} characters of text")
        
        # Split text into chunks using LangChain
        text_splitter = RecursiveCharacterTextSplitter(
            chunk_size=1000,
            chunk_overlap=200,
            length_function=len,
            separators=["\n\n", "\n", ". ", " ", ""]
        )
        chunks = text_splitter.split_text(text)
        log(log_file, f"  Created {len(chunks)} chunks")
        
        # Delete old chunks and insert new ones
        delete_chunks_for_document(cursor, document_id)
        insert_chunks(cursor, document_id, chunks)
        log(log_file, f"  ✓ Successfully processed {filename} - {len(chunks)} chunks created")
        
        return True
    except Exception as e:
        log(log_file, f"  ERROR processing {filename}: {str(e)}")
        return False


def main():
    """Main execution function."""
    if len(sys.argv) < 3:
        print("Usage: python generate_chunks.py <customgpt_id> <log_file>")
        sys.exit(1)
    
    customgpt_id = int(sys.argv[1])
    log_file = sys.argv[2]
    
    # Initialize log file
    with open(log_file, 'w') as f:
        f.write(f"Chunk Generation Log - CustomGPT ID: {customgpt_id}\n")
        f.write("=" * 60 + "\n\n")
    
    log(log_file, f"Starting chunk generation for CustomGPT ID: {customgpt_id}")
    
    try:
        # Connect to database
        connection = get_db_connection()
        log(log_file, "Database connection established")
        
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
                log(log_file, "No documents found for this CustomGPT")
                return
            
            log(log_file, f"Found {len(documents)} document(s) to process")
            log(log_file, "")
            
            # Process each document
            success_count = 0
            for idx, doc in enumerate(documents, 1):
                log(log_file, f"Document {idx}/{len(documents)}:")
                if process_document(cursor, doc['id'], doc['file_id'], log_file):
                    success_count += 1
                log(log_file, "")
            
            # Commit all changes
            connection.commit()
            log(log_file, "All changes committed to database")
            
        connection.close()
        log(log_file, "")
        log(log_file, "=" * 60)
        log(log_file, f"✓ Chunk generation completed!")
        log(log_file, f"  Successfully processed: {success_count}/{len(documents)} documents")
        
    except Exception as e:
        log(log_file, "")
        log(log_file, "=" * 60)
        log(log_file, f"✗ ERROR: {str(e)}")
        import traceback
        log(log_file, traceback.format_exc())
        sys.exit(1)


if __name__ == '__main__':
    main()
