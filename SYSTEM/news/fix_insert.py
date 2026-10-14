import os
d = r'D:\xampp\htdocs\alan_php_mysql\SYSTEM\news'
with open(os.path.join(d, 'edit.php'), 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

# Replace the INSERT statement to remove the subquery
old_insert = '''$statement = $pdo->prepare(
'INSERT INTO news (title, title_2, description, news_pic, `CHK`, data_sort) VALUES (
:title,
:title_2,
:description,
:news_pic,
:chk,
(SELECT COALESCE(MAX(data_sort), 0) + 1 FROM news)
)
)'''

new_insert = '''$statement = $pdo->prepare(
'INSERT INTO news (title, title_2, description, news_pic, `CHK`, data_sort) VALUES (
:title,
:title_2,
:description,
:news_pic,
:chk,
0
)
)'''

content = content.replace(old_insert, new_insert)

with open(os.path.join(d, 'edit.php'), 'w', encoding='utf-8', errors='ignore') as f:
    f.write(content)

print('Fixed INSERT statement - removed subquery that caused MySQL error 1093')
