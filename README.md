# 📋 Checking the PDF Assistants with Real PDFs

This project includes PDF assistants (`ZieglerPdfAssistant`, `TransalliancePdfAssistant`) to parse transport booking PDFs.

---

## ⚙️ Setup

First install dependencies:

```bash
composer install
```

Make sure you have **Poppler** (`pdftotext`) installed:

```bash
sudo apt install poppler-utils   # Debian/Ubuntu
```

---

## ✅ Run Unit Tests

Run all tests:

```bash
php artisan test
```

Or filter by assistant:

```bash
php artisan test --filter=ZieglerPdfAssistantTest
php artisan test --filter=TransalliancePdfAssistantTest
```

---

## 🔎 Run Assistants in Tinker with Real PDFs

1. Open Laravel Tinker:

```bash
php artisan tinker
```

2. Call the helper to process a Ziegler PDF:

```php
process_pdf(storage_path('pdf_client_test/ZieglerPdfAssistant_1.pdf'));
```

3. Process another Ziegler PDF:

```php
process_pdf(storage_path('pdf_client_test/ZieglerPdfAssistant_2.pdf'));
```

4. Process a Transalliance PDF:

```php
process_pdf(storage_path('pdf_client_test/TransalliancePdfAssistant_1.pdf'));
```

---

## 📂 Notes

- Place your PDF samples under:

```
storage/pdf_client_test/
```

- `process_pdf($path)` will:
  - Extract text with `pdftotext`
  - Detect the correct assistant (Ziegler / Transalliance)
  - Parse the PDF
  - Return the parsed order array
