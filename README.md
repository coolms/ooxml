# coolms/ooxml

[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**Writing Office Open XML containers in pure PHP.** A deterministic ZIP writer,
OPC parts, content types, relationships, and the escaping OOXML needs — and no
knowledge of workbooks, documents or decks.

```php
$package = new OpcPackage();
$package->declareDefault('rels', 'application/vnd.openxmlformats-package.relationships+xml');
$package->declareDefault('xml', 'application/xml');

$package->addPart('xl/worksheets/sheet1.xml', $sheetXml, self::WORKSHEET_TYPE);
$rId = $package->relate('xl/workbook.xml', self::REL_NS . '/worksheet', 'xl/worksheets/sheet1.xml');

$package->addPart('xl/workbook.xml', $workbookXml, self::WORKBOOK_TYPE);
$package->relate('', self::REL_NS . '/officeDocument', 'xl/workbook.xml');

file_put_contents('book.xlsx', $package->toBytes());
```

## What it is for

`.xlsx`, `.docx` and `.pptx` differ only in their parts. The container around
them — the zip, `[Content_Types].xml`, the `.rels` graph — is identical, and is
the part that decides whether Office opens the file at all. This package is that
container; the parts belong to whatever knows the document model.

## What it deliberately refuses

- **A part with no content type.** Not a default — a package Office rejects.
- **A relationship target outside the source part's folder.** Legal OOXML, but
  it needs `../` segments this has not reasoned about, and a path written by
  guesswork opens as a document with a piece silently missing.
- **Text it cannot escape.** A failed strip throws rather than returning an
  empty string, because an empty cell and a destroyed one look identical.

## Deterministic by design

Entries carry a fixed timestamp, so the same input produces the same bytes. PHP's
own `ZipArchive` cannot write to a string — every archive goes through a
temporary file — and stamps the current time, which means no test may ever
compare two artifacts. That, not performance, is why the ZIP writer is here.

## Requirements

PHP 8.5+, `ext-zlib`. No runtime dependencies.

## Licence

MIT.
