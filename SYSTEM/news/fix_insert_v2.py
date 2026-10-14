import os
d = r'D:\xampp\htdocs\alan_php_mysql\SYSTEM\news'
with open(os.path.join(d, 'edit.php'), 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

# Replace the subquery
old_subquery = '(SELECT COALESCE(MAX(data_sort), 0) + 1 FROM news)'
new_subquery = '0'
content = content.replace(old_subquery, new_subquery)

with open(os.path.join(d, 'edit.php'), 'w', encoding='utf-8', errors='ignore') as f:
    f.write(content)

print('Fixed: replaced subquery with 0')
