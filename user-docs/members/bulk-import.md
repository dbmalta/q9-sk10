# Bulk Import

Bulk import lets you add many members at once from a CSV file.

## Preparing your CSV

Your CSV file must have a header row. Required columns:

- `first_name`
- `last_name`
- `date_of_birth` (format: `YYYY-MM-DD`)

Optional columns: `email`, `phone`, `section` (must match an existing section name).

Download the template CSV from **Members → Bulk Import** for the correct format.

## Running the import

1. Go to **Members → Bulk Import**
2. Select your CSV file
3. Map the columns if the wizard cannot auto-detect them
4. Review the preview — rows with errors are highlighted
5. Click **Import** to create the records

## After import

A summary shows how many records were created and any rows that were skipped. Skipped rows can be downloaded as a CSV for correction and re-import.
