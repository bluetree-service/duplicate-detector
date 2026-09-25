# Duplicated file detector

Allow finding and optionally delete duplicated files. Works on multiple threads to improve performance.

If you don't have, or you don't want to install PHP, just use it as docker container.

## Examples

### Docker

`docker run -v {local path with files to check}:/duplicates -it --rm chajr/duplicate-detector detector -ipS -t 4`

That command execute checking file duplications, on 4 threads, progress bar and with interactive selecting files to delete.

For more information run `docker run -it --rm chajr/duplicate-detector detector -h`

By default, `duplicate-detector` search for files in `/duplicates`, but you can link your directory, or directories to container
and provide them into a detector.

`docker run -v ./dir1:/dir1 -v ./dir2:/dir2 -it --rm chajr/duplicate-detector detector -ipS -t 4 /dir1 /dir2`

To save list of duplicated files as HTML documents, mount `/out` directory and use `-H` (as last option,
otherwise next argument is taken as output directory):

`docker run -v ./dir1:/duplicates -v ./out:/out -it --rm chajr/duplicate-detector detector -S -t 4 -H`

It creates `index.html` and `duplicates-0001.html`, `duplicates-0002.html`... pages with 100 duplications each.
Duplications are sorted by directory. Old `duplicates-*.html` pages in output directory are removed.

**This is beta version, so use it carefully**

musi mieć dostęp do /tmp