DOCUMENT OCR WITH iLOVEPDF — SWITCHED OFF
=========================================
This folder holds the OCR feature that read a scanned document (passport, CNIC, transcript...) with the
iLovePDF API and filled the student form. It was removed from the portal on request. Nothing here is loaded
by the portal, and this folder should not be uploaded to the hosting account.

To bring it back later, ask your developer to:
  1. copy app-ocr.php to portal/app/ocr.php and env.php to portal/app/env.php, and require both in
     portal/app/bootstrap.php (after helpers.php);
  2. copy ocr-endpoint.php to portal/ocr.php;
  3. copy .env.example to portal/app/.env and fill in ILOVEPDF_PUBLIC_KEY and ILOVEPDF_SECRET_KEY
     (iLoveAPI developer console > your project > keys). The keys are used only on the server;
  4. add the "Scan a document" panel back to the student form (it called ocr.php and filled the fields
     for the person to check before saving).
The parts of the form and the documents checklist it relied on have changed since, so it needs testing again.
