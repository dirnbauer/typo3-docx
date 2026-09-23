..  include:: /Includes.rst.txt

..  _page-sync:

=====================
Edit pages in Word
=====================

A page and its content elements can be edited as one Word document — in the
editor built into the backend, or in Microsoft Word, LibreOffice or any other
word processor — and imported back. A Word document that never came from
TYPO3 can be imported as new pages: it is split into content elements that fit
its structure.

Nothing is written before the editor has seen exactly what will change.

..  contents::
    :local:
    :depth: 1

Where to find it
================

-   The Page module has a :guilabel:`Word` menu in its DocHeader:
    :guilabel:`Edit in Word`, :guilabel:`Download as Word document` and
    :guilabel:`Import Word document as subpages…`.
-   The page tree's context menu offers :guilabel:`Edit in Word` and
    :guilabel:`Import Word document as subpages…`.
-   On the command line: ``docx-editor:page:export`` and
    ``docx-editor:page:import`` (see :ref:`page-sync-cli`).

Each entry only appears where the backend user may use it: editing content
needs content-edit permission on the page and write access to ``tt_content``;
importing subpages needs permission to create pages below it.

The document
============

The page title is the first line, formatted as Heading 1. Every content
element follows as a *content control* (Word's :guilabel:`Developer` tab calls
them that) named after its type — "Text & Media", "Accordion" — and inside it
every field as a control of its own: header, text, pictures, and for Content
Blocks elements with collections one control per item.

-   Headings, paragraphs, bold, italic, underline, strike-through, sub- and
    superscript, links, bulleted and numbered lists, tables, quotes and code
    are kept both ways.
-   Pictures of image fields are embedded as a copy scaled to the size Word
    shows them (see :ref:`page-sync-pictures`); alternative text and caption
    travel with them. New or replaced pictures are stored in the folder from
    the settings, identical pictures only once.
-   Link fields (a button's target, a teaser link) are shown read-only as what
    they point to: *Page: About us (/Home/Company/About us/)*, *File:
    brochure.pdf*, *E-mail: office@example.com*, the record's title, the phone
    number or the URL. The hyperlink keeps the stored target, the manifest the
    stored value; the import never changes a link field.
-   Elements and fields the editor may not change (plugins, file fields with
    other files than pictures, fields excluded from translation…) are shown
    read-only with a short summary.
-   With more than one column in the backend layout, each column starts with
    a heading "Column: Main" etc.
-   A hidden signed part of the document records which record and field every
    control belongs to, what each field held at export time and which file
    each picture stands for.

Edit in Word
============

:guilabel:`Edit in Word` opens the page in the embedded editor, every control
framed. Edit text inside the frames, write new sections between elements,
delete or move whole elements. :guilabel:`Review and save` (also
:guilabel:`File > Save` and :kbd:`Ctrl+S` / :kbd:`Cmd+S`) opens the review.
:guilabel:`Word > Download as Word document` downloads the page for editing in
another program; :guilabel:`Word > Upload Word document…` reviews a document
edited elsewhere the same way. The language selector switches between the
page's translations.

The review
==========

The review lists every element with what would happen to it:

..  list-table::
    :header-rows: 1
    :widths: 25 75

    * - Change
      - Meaning
    * - New
      - Content written between elements, with the content type proposed for
        it. The select offers the other types that fit, with how well.
    * - Changed
      - Fields edited in Word; the new text is shown.
    * - Conflict
      - A field changed in Word *and* in TYPO3 since the export. Choose which
        version to keep; without a choice TYPO3's stays.
    * - Removed in Word
      - Deleted only when you tick :guilabel:`Delete`.
    * - Translate
      - An element of the default language that gets its translation.
    * - Read-only / Not imported
      - Nothing is written.

Untick an element to leave it out. When the order of elements changed, a
checkbox applies Word's order. :guilabel:`Import` writes everything in one go
through the DataHandler, as the current backend user and in the current
workspace — history, workspaces, permissions and hooks work as for any edit.

If someone changed the page between review and import, nothing is written and
you are asked to review again.

Import as subpages
==================

:guilabel:`Import Word document as subpages…` uploads a document and creates
pages below the page:

-   **One page** — its title is the first Heading 1.
-   **A page per Heading 1** or **a page per page break**.

The rest of each page is split into parts at its headings, page breaks and
horizontal lines; tables, quotes and code stand on their own. Every part
becomes the content element that fits it best among the types the editor may
create in the page's first column. New pages are created hidden (see
:ref:`page-sync-settings`) and after the existing subpages.

How the content type is chosen
==============================

For every part, each allowed type is scored on how well the part fills its
fields: a heading into the header, text into the body, a picture into an image
field, question/answer pairs or numbered steps into a collection, a quote into
a quote field. Types that would lose content or leave required fields empty
score lower. The allowed types are the ones the New Content Element wizard
offers for that column — backend layout restrictions, TSconfig and the
editor's permissions included.

When two or more types fit about equally well and the extension
`webcon_jev <https://github.com/dirnbauer/typo3-webcon-jev>`__ (0.2.1 or
later) is installed and configured, Jev chooses among them; the review shows
its choice and how sure it was. Below the confidence threshold, or without
Jev, the best structural fit is kept and the element is marked *please check*.
Runs are logged in webcon_jev's run log as "Word import (docx_editor)".

Translations and workspaces
===========================

-   Each language is its own document. In a translation, fields that follow
    the default language are read-only; elements not translated yet appear
    with their default-language text and are translated when edited.
-   In connected mode, new elements are added in the default language only.
-   In a workspace, everything is written as workspace versions. A document
    exported in another workspace can still be imported; its elements are
    compared with the current workspace.

..  _page-sync-pictures:

Pictures
========

A page's pictures are embedded as copies made by TYPO3's image processing, in
the file's own format: as many pixels as Word needs to show the picture — at
most as wide as the text — at ``pageSync.pictureResolution`` pixels per inch
(150 by default, what Word calls "Web"), and no edge longer than
``pageSync.pictureMaxEdge`` (2000). Smaller pictures, GIFs and pictures TYPO3
cannot process are embedded as they are. A page full of large pictures
exports to a fraction of its former size and fits the upload limit on the way
back.

The copy only stands in for the file: every picture is named after its file
reference (``typo3:sys_file_reference:31``, Word shows the name in the
:guilabel:`Selection Pane`), and the manifest records which copy stands for
which file. On import

-   a picture that still holds the embedded copy is the same file reference —
    alt text and caption edits are applied to it, the file stays as it is,
    also when a word processor renamed the picture;
-   a picture replaced in Word (inserted anew, or :guilabel:`Change Picture`,
    which keeps the name) is stored as a new file in the picture folder, as
    Word holds it;
-   an exported picture used a second time refers to its file again.

Word can compress pictures when it saves (:guilabel:`File > Options >
Advanced > Image Size and Quality`); copies at 220 ppi or less are left alone.
A picture a word processor did recompress counts as replaced. Documents
exported by 2.2 or earlier hold the files themselves; they are recognised as
before.

..  _page-sync-cli:

Command line
============

..  code-block:: bash

    # The page as a Word document (language 0, live workspace)
    vendor/bin/typo3 docx-editor:page:export 42 --language=0 --out=/tmp/

    # What importing it would change — writes nothing
    vendor/bin/typo3 docx-editor:page:import /tmp/about-us-en-gb.docx --pid=42

    # Import it; delete removed elements; Word wins conflicts
    vendor/bin/typo3 docx-editor:page:import /tmp/about-us-en-gb.docx --pid=42 \
        --apply --confirm-deletions --prefer=word

    # A document as new pages below page 7, a page per Heading 1
    vendor/bin/typo3 docx-editor:page:import handbook.docx --parent=7 --split=h1 --apply

Options: ``--workspace``, ``--language`` (with ``--pid``), ``--no-jev``. The
commands run as TYPO3's command-line user. The export prints the document's
size and warns when it is larger than an import accepts.
``--out`` takes a file or a directory; a path ending in ``/`` is created.

What Word cannot carry
======================

-   Fonts, colours, sizes, alignment and spacing are Word's business; they are
    not imported. CSS classes and inline styles of a rich text field are
    removed when that field is changed in Word (the review says so).
-   Rich text with embedded media, iframes or other markup Word cannot
    represent is read-only.
-   Two quotes or code blocks in a row become one in Word.
-   Text boxes are imported as paragraphs; charts, SmartArt, and pictures in
    EMF, WMF or TIFF are left out with a warning. Headers, footers, footnotes
    and comments are ignored; tracked insertions count, tracked deletions do
    not.
-   Merged table cells are split again; table cells hold text only.
-   Elements in containers or in columns the backend layout does not show are
    not part of the document.
-   Link fields are read-only: the document shows what they point to, and a
    new link target is set in TYPO3.
-   Pictures travel as scaled copies (:ref:`page-sync-pictures`). A page with
    very many pictures can still export to more than
    ``pageSync.maxUploadMegabytes``; lower ``pageSync.pictureResolution`` or
    raise the limit to import such a document again.

When a word processor removes the content controls (for example Word's
:guilabel:`Remove Content Control`, or LibreOffice converting them),
elements are recognised by their bookmarks, and failing that by their text.
