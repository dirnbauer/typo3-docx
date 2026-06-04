..  include:: /Includes.rst.txt

..  _developer:

=========
Developer
=========

Architecture
============

- **PHP**: FAL services, AJAX controllers, ``ProcessFileListActionsEvent`` listener
- **Fluid 5**: Module templates and translated labels
- **Lit**: ``typo3-docx-editor`` custom element (TYPO3 glue)
- **Vite bundle**: React adapter for ``@eigenpal/docx-editor-react``

Quality gates
=============

..  code-block:: bash
    :caption: Run the full CI suite locally

    composer install
    Build/Scripts/runTests.sh -s ci

Suites: ``unit``, ``phpstan``, ``composer``, ``assets``, ``ci``.

PHPStan
=======

..  code-block:: bash

    vendor/bin/phpstan analyse

Extension boundaries
====================

Keep FAL and permission logic in
:file:`Classes/Service/DocxFileService.php`. Do not embed document binaries in
collaboration tables — revisions store hashes and counters only.
