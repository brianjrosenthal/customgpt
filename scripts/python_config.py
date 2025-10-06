"""
Database configuration for Python scripts
This file should be kept in sync with config.local.php
"""

import os

# Database configuration
# These should match the values in config.local.php
DB_CONFIG = {
    'host': os.environ.get('DB_HOST', 'localhost'),
    'database': os.environ.get('DB_NAME', 'customgpt'),
    'user': os.environ.get('DB_USER', 'root'),
    'password': os.environ.get('DB_PASS', ''),
    'charset': 'utf8mb4'
}

# Alternative: Load from a config file if environment variables not set
def get_db_config_from_php():
    """
    Parse config.local.php to extract database credentials.
    This is a fallback if environment variables are not set.
    """
    import re
    config_file = os.path.join(os.path.dirname(__file__), '..', 'config.local.php')
    
    if not os.path.exists(config_file):
        raise FileNotFoundError(f"Config file not found: {config_file}")
    
    with open(config_file, 'r') as f:
        content = f.read()
    
    # Extract database configuration using regex
    db_host = re.search(r"define\('DB_HOST',\s*'([^']*)'\)", content)
    db_name = re.search(r"define\('DB_NAME',\s*'([^']*)'\)", content)
    db_user = re.search(r"define\('DB_USER',\s*'([^']*)'\)", content)
    db_pass = re.search(r"define\('DB_PASS',\s*'([^']*)'\)", content)
    
    if not all([db_host, db_name, db_user, db_pass]):
        raise ValueError("Could not parse database configuration from config.local.php")
    
    return {
        'host': db_host.group(1),
        'database': db_name.group(1),
        'user': db_user.group(1),
        'password': db_pass.group(1),
        'charset': 'utf8mb4'
    }

# Try to get config from PHP file if environment variables are not complete
# Check if password is empty (not just missing) since that's the most common case
if not DB_CONFIG['password'] or not all([DB_CONFIG['host'], DB_CONFIG['database'], DB_CONFIG['user']]):
    try:
        DB_CONFIG = get_db_config_from_php()
    except Exception as e:
        print(f"Warning: Could not load config from PHP file: {e}")
        print("Using environment variables or defaults")
