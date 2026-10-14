import os
d = r'D:\xampp\htdocs\alan_php_mysql\SYSTEM\news'
with open(os.path.join(d, 'edit.php'), 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

for i, line in enumerate(content.split('\n')):
    if 'INSERT' in line.upper():
        print(f'Line {i+1}: {line.strip()[:200]}')
