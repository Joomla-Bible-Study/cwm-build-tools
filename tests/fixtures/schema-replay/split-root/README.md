The layout Proclaim actually has: the manifest at the top, and everything its
relative paths point at one directory down.

Joomla resolves `<schemapath>` against `extension_root`, not against the
manifest's own directory. The two coincide in an installed extension, so a
fixture where the manifest sits beside `sql/` cannot tell the difference — it
passes whether or not the distinction is implemented. This one fails unless it
is.
