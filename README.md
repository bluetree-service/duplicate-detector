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

**This is beta version, so use it carefully**

musi mieć dostęp do /tmp