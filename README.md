# PHP_BjSFFMpeg
Creating simple video GIF previews with FFMpeg in PHP


This is split into two major parts:

<h3>GIF manipulation</h3>

I used the information I provide here: <a href="https://www.codeproject.com/Articles/1042433/Manipulating-GIF-Color-Tables">[1042433] Manipulating GIF Color Tables</a>

The class does not create GIF data itself, it concats GIF images created by PHP's libraries into a working annimated GIF file.


<h3>FFMpeg calling</h3>

This provides functions to get movie file information, to extract single frames from a movie file and to create an annimated square GIF with an annimated border from a movie file taking evenly distributed frames.
