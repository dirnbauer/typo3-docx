..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

Open and edit
=============

#.  Open :guilabel:`Media` > :guilabel:`Filelist` and browse to a `.docx` file.
#.  Choose the :guilabel:`Edit DOCX` action. In the list view it is one of the
    file's buttons; in the tile view, right-click the tile and choose it from
    the context menu.
#.  Edit in the full-page editor. The style picker offers
    :guilabel:`Normal` and :guilabel:`Heading 1`–:guilabel:`Heading 4`; the same
    headings are available as H1–H4 buttons at the end of the toolbar.

Word keeps headings a document has not used yet *latent* — undefined in the
file. While a document is open the editor adds Word's definitions for the
missing ones, and on save it keeps only those you applied, so an untouched
document is saved without them. Styles are matched by their Word name, so a
German Word's ``Standard`` and ``Überschrift 1`` appear as Normal and
Heading 1.

Menus
=====

-   :guilabel:`File`: :guilabel:`Save`, :guilabel:`Print` and
    :guilabel:`Page setup`. There is no :guilabel:`Open` — it would replace
    the FAL file with a local one.
-   :guilabel:`Format`, :guilabel:`Insert`: text and paragraph formatting, the
    paragraph dialog, images, tables, notes, breaks, table of contents.
-   :guilabel:`Review`: show paragraph marks, protect the document for forms.
    Tracked-change review and comment threads are not included (see
    :ref:`introduction`); existing tracked changes and comments are kept.

..  _usage-print:

Print
=====

:guilabel:`Print` in the DocHeader, :guilabel:`File > Print` or
:kbd:`Ctrl+P` / :kbd:`Cmd+P` opens the browser's print dialog with the
document's pages as the editor lays them out: one sheet per page, at the
document's paper size (A4, Letter, a landscape section in landscape), with
the margins of the document and without the editor's frames and marks. Choose
:guilabel:`Save as PDF` in the dialog for a PDF. The same works in
:guilabel:`Edit in Word` (:ref:`page-sync`) — there the content-control frames
are left out as well.

Save
====

-   :guilabel:`Save` in the DocHeader or :kbd:`Ctrl+S` / :kbd:`Cmd+S` writes the
    document back to the same FAL file.
-   The Save dropdown offers :guilabel:`Save and close` and :guilabel:`Save as…`.
    :guilabel:`Save as…` opens the folder browser, then a dialog for the file
    name (``.docx`` is appended), and the editor reopens the new file. An
    existing name gets a ``_01`` suffix.
-   :guilabel:`Close` returns to the folder in the file list. With unsaved
    changes it asks first — keep editing, discard the changes, or save and
    close — like record editing does; leaving the page any other way lets the
    browser warn.
-   :guilabel:`Download` fetches the stored file.
-   Feedback arrives as backend notifications ("Saved to fileadmin / …"),
    error messages in the language of the backend user.

Permissions
===========

Read permission on storage and file opens the document in viewing mode; write
permission enables saving. The :guilabel:`Edit DOCX` action is only shown for
readable files.

Working together
================

A badge next to the heading shows how many editors currently have the file
open; hover it for their names. Every save increments a revision counter; if
someone else saved while you were editing, your save is rejected and a warning
callout offers :guilabel:`Reload document`.

Light and dark mode
===================

The editor follows the colour scheme of the backend: its menu bar, toolbar,
rulers, menus and dialogs use TYPO3's design tokens. The document page stays
white, as Word shows it; the upstream editor's own dark mode, which inverts the
page, is not used.

Fonts
=====

Metric-compatible open fonts stand in for Calibri, Cambria, Arial, Times New
Roman, Courier New and Century Gothic, so lines and pages break where Word
breaks them. They load from the extension, and only when a document uses the
family. A notice under the toolbar names fonts the document uses that are not
available; they are drawn with a substitute.
