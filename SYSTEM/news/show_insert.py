import os
d = r'D:\xampp\htdocs\alan_php_mysql\SYSTEM\news'
filepath = os.path.join(d, 'edit.php')

with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

# Show the INSERT area
idx = content.find('INSERT INTO news')
if idx >= 0:
    print('INSERT statement:')
    # Show 10 lines after INSERT
    lines = content.split('\n')
    for i, line in enumerate(lines):
        if 'INSERT INTO news' in line:
            for j in range(i, min(i+10, len(lines))):
                print(f'{j+1}: {lines[j].strip()}')
            break
    
# Also check for any SQL syntax errors by showing the full statement
print('\n--- Checking for syntax issues ---')
if 'data_sort' in content:
    print('data_sort reference found')
if 'FROM news' in content:
    print('FROM news found in SQL')
else:
    print('No FROM news in SQL (good)')
