# Catalog Upload Feature — Complete Overview

## Purpose

Allow vendors (via their client portal) to upload a product catalog file (CSV/XLSX/XLS), map their columns to VIT eLink fields, validate the data, store it in the application's `catalog_items` table, and eventually generate a VIT-compliant Excel file for submission to the VIT API.

---

## High-Level Flow

```
Upload File → Map Columns → Validate Rows → Store in catalog_items → Generate Excel → Submit to VIT API
   Step 1         Step 2          Step 3             Step 4             Step 5           Step 6
```

---

## Step-by-Step Breakdown

### Step 1: File Upload (`VendorCatalogUpload::uploadFile()`)

**File:** `app/Livewire/Vendor/VendorCatalogUpload.php` (line 127)

- Vendor provides a **Catalog Name** and selects a file (CSV, XLS, or XLSX, up to 50MB)
- File is stored on DigitalOcean Spaces (`spaces` disk) at `catalog-uploads/{vendor_id}/`
- A `CatalogUpload` record is created with:
    - `vendor_id`, `client_id`, `catalog_name`, `file_path`, `disk`, `file_type`
    - `status = 'uploaded'`
- The file is inspected (header row + sample rows extracted) using `CatalogFileInspectionService`
- The UI advances to the **Mapping** step

### Step 2: Column Mapping (`VendorCatalogUpload` — mapping step)

**File:** `app/Livewire/Vendor/VendorCatalogUpload.php` (line 146-306)

- Vendor sees their file's column headers alongside a sample value from the first data row
- For each column, they select which VIT field it maps to (or "Skip this column")
- **Auto-suggestion** runs in two passes:
    1. **Saved template match** — If the vendor has a saved mapping template from a previous upload, columns are auto-matched by name
    2. **Fuzzy match** — Unmapped columns are scored against VIT field keys/labels using `similar_text()`. Required fields need ≥85% confidence, optional fields ≥65%
- A progress bar shows how many required fields are mapped
- Vendor can optionally save the mapping as a reusable template
- On **Confirm Mapping**:
    - `CatalogUploadColumnMapping` records are created (one per column, linking `column_index` → `catalog_field_id`)
    - Upload status changes to `'queued'`
    - `ProcessCatalogUploadJob` is dispatched

### Step 3: Row Validation (`ProcessCatalogUploadJob`)

**File:** `app/Jobs/ProcessCatalogUploadJob.php`

- Reads the full file using PhpSpreadsheet
- For each data row (starting at row 2):
    - Maps raw column values to VIT field keys using the column mappings
    - Validates each field using `CatalogRowValidator`:
        - Required fields must have a value
        - Conditional fields are checked if their trigger field has a value
        - Type validation (number, decimal, boolean, max length)
        - Multi-value fields are split by their join separator
    - Stores a `CatalogUploadRow` record with:
        - `data` (mapped field values as JSON)
        - `raw_data` (original column values as JSON)
        - `status` = `'valid'` or `'invalid'`
        - `errors` (validation error messages, if any)
- Rows are inserted in batches of 200 for performance
- After all rows are processed:
    - If there are valid rows → status changes to `'processing_items'`, dispatches `ProcessValidatedRowsJob`
    - If no valid rows → status changes to `'completed'` with counts

### Step 4: Catalog Item Creation (`ProcessValidatedRowsJob`)

**File:** `app/Jobs/ProcessValidatedRowsJob.php`

- Queries **only** `CatalogUploadRow` records with `status = 'valid'`
- For each valid row:
    - Builds an attribute map from the validated data, mapping field keys to `CatalogItem` database columns (e.g. `seller_sku` → `vendor_sku`, `list_price` → `list_price`, etc.)
    - **System-derived fields** (like `vendor_name`) are populated from the vendor record, not the file
    - **NOT NULL defaults** are applied for missing values (e.g. `product_type = 'General'`, `unspsc_code = '00000000'`)
    - **Deduplication by vendor SKU**: If a `CatalogItem` already exists with the same `vendor_id` + `vendor_sku`:
        - Compares all fields to detect changes
        - Updates the existing record if anything changed
        - Skips (counts as "unchanged") if nothing changed
    - If no existing item found, creates a new `CatalogItem` with a UUID
- All operations happen within a **database transaction** — if anything fails, everything is rolled back
- On completion, the upload status is set to `'completed'` with accurate counts:
    - `success_rows` = created + updated
    - `updated_rows` = count of updated items
    - `skipped_rows` = count of unchanged items
    - `error_rows` = count of invalid rows

### Step 5: Excel Generation (`SubmissionService`)

**File:** `app/Services/SubmissionService.php`

- Triggered manually or automatically after catalog items are created
- Queries `CatalogItem` by `vendor_id` + `catalog_name`
- Uses `CatalogExcelGenerator` to build a VIT-compliant `.xlsx` workbook:
    - Column order, headers, and formatting come from `CatalogFieldDefinition` table (admin-managed)
    - Product images are embedded as real images (not URLs)
    - Vendor name is pulled from the vendor record
- Stores the Excel file at `submissions/vendor-{id}/catalog-{name}-{timestamp}.xlsx`
- Creates a `Submission` record with:
    - `status = 'pending_upload'`
    - `product_count` = number of items
    - `file_path` pointing to the generated Excel

### Step 6: VIT API Submission (`UploadSubmissionToVit`)

**File:** `app/Jobs/UploadSubmissionToVit.php`

- Takes a `Submission` record and uploads the Excel file to VIT's catalog API via `VitApiClient`
- On success: status → `'uploaded'`, stores API response
- On failure: status → `'failed'`, increments `upload_attempts`, stores error message
- Notifications are sent on both success and failure

---

## Database Tables Involved

| Table                            | Purpose                                               |
| -------------------------------- | ----------------------------------------------------- |
| `catalog_uploads`                | One record per file upload session                    |
| `catalog_upload_column_mappings` | Maps each file column to a VIT field                  |
| `catalog_upload_rows`            | One record per row from the file (valid or invalid)   |
| `catalog_fields`                 | Admin-managed list of VIT eLink fields                |
| `catalog_items`                  | The final product records (one per unique vendor SKU) |
| `catalog_item_images`            | Product images linked to catalog items                |
| `submissions`                    | Generated Excel files ready for VIT upload            |
| `vendor_mapping_templates`       | Saved column mapping configurations                   |

---

## Key Design Decisions

1. **Two-phase processing**: File parsing/validation (Phase 8A) and item creation (Phase 8B) are separate jobs. This allows the first job to complete quickly and the second to handle deduplication/updates independently.

2. **Only valid rows reach catalog_items**: The `ProcessValidatedRowsJob` explicitly filters `WHERE status = 'valid'`. Invalid rows are stored with their errors for display but never processed further.

3. **Deduplication by vendor SKU**: Re-uploading the same file updates existing items rather than creating duplicates. Items with no changes are skipped entirely.

4. **System-derived fields**: Fields like "Seller" come from the vendor's account record, not the uploaded file. They're marked `is_system_derived = true` in `catalog_fields` and excluded from the mapping screen.

5. **NOT NULL defaults**: The `catalog_items` table has several required columns. When uploaded data is missing a value, sensible defaults are applied (e.g. `unit_of_measure = 'EA'`, `list_price = 0.00`).

6. **Completeness scoring**: Each `CatalogItem` automatically calculates a completeness score (0-100) and status (`incomplete`/`acceptable`/`excellent`) based on which fields are filled.

---

## Status Flow for a CatalogUpload

```
uploaded → mapping → queued → processing → processing_items → completed
                                                                    ↓
                                                               (failed)
```

- `uploaded`: File stored, awaiting mapping
- `mapping`: Vendor is mapping columns
- `queued`: Mapping confirmed, job dispatched
- `processing`: File is being read and validated
- `processing_items`: Valid rows are being converted to CatalogItems
- `completed`: All done
- `failed`: Something went wrong (error message stored in `failure_reason`)

---

## UI Components

| Component                         | File                                                              | Purpose                                             |
| --------------------------------- | ----------------------------------------------------------------- | --------------------------------------------------- |
| `VendorCatalogUpload` (Livewire)  | `app/Livewire/Vendor/VendorCatalogUpload.php`                     | Main upload wizard controller                       |
| `vendor-catalog-upload.blade.php` | `resources/views/livewire/vendor/vendor-catalog-upload.blade.php` | 4-step wizard UI (Upload → Map → Process → Summary) |

The UI is a single-page wizard with 4 steps:

1. **Upload** — File picker + catalog name
2. **Map Columns** — Table with dropdowns for each column
3. **Processing** — Polling screen with live counts
4. **Summary** — Results with created/updated/error counts, failed row details
