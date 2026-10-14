import os
d = r'D:\xampp\htdocs\alan_php_mysql\SYSTEM\news'
with open(os.path.join(d, 'edit.php'), 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

# Check if the subquery is gone
if 'SELECT COALESCE(MAX(data_sort), 0) + 1 FROM news' in content:
    print('ERROR: Subquery still present')
else:
    print('OK: Subquery removed')

# Show the INSERT statement area
idx = content.find('INSERT INTO news')
if idx >= 0:
    print('\\nINSERT statement found:')
    print(content[idx:idx+300])
