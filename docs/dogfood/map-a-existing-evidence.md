# Historical evidence inventory

This inventory exists to prevent accidental re-running of already-known conclusions as if they were fresh MAP-A measurements.

| Replay | Historical Search evidence | Historical structural evidence | Valid as fresh MAP-A? |
| --- | --- | --- | --- |
| portable-ascii#62 | `ASCII.php`, `ASCII::to_ascii_replace()` and an `AsciiTest` lead; declaration-free `ascii_by_languages.php` absent even at wider Search limits | no fresh C measurement retained | no |
| Simple-PHP-Code-Parser#101 | top 15 included regression test, fixture, `PhpCodeParser.php` rank 5 and `PHPProperty::readObjectFromPhpNode` rank 8; `PHPClass.php` absent | map contained `PHPClass::readObjectFromPhpNode -> calls -> PHPProperty::readObjectFromPhpNode`; later Loop composition recovered the caller | no; later workflow plan also contains known-file contamination |
| Simple-PHP-Code-Parser#60 | `PhpCodeParser.php` rank 3/10 from unchanged issue text | none required/retained | no |

These observations may be used to falsify a newly measured result or detect harness drift. They must not populate `map-a-results.tsv`.
