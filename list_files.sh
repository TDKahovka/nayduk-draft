#!/bin/bash
OUT_FILE="files_list.txt"

find . -type f -o -type d | while read item; do
    created=$(stat -c %w "$item")
    modified=$(stat -c %y "$item")
    if [ "$created" = "-" ]; then
        created="N/A"
    fi
    echo "Имя: $item" >> "$OUT_FILE"
    echo "  Создан: $created" >> "$OUT_FILE"
    echo "  Изменён: $modified" >> "$OUT_FILE"
    echo "" >> "$OUT_FILE"
done

echo "Список файлов сохранён в $OUT_FILE"
