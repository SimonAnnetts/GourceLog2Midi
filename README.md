# GourceLog2Midi
Use a custom Gource timing log to create a midi file.

You must use my customised version of Gource:
https://github.com/SimonAnnetts/Gource



An example workflow:

~~~
./Gource/gource  --camera-mode overview --max-user-speed 300 --highlight-users --bloom-intensity 0.5 --bloom-multiplier 0.2 --file-filter '(?<!\.[ct]s)$' --file-filter '^(?!src).*$' -a 2 -c 2 --multi-sampling --no-vsync --disable-auto-rotate -720x720 --title "My Title" --filename-colour a09080 --dir-colour 607090  --key -o output.ppm -r 25 --output-timing-log output.log --hash-seed 252 --background-image background-image.jpg --dont-stop my-git-directory

nice ffmpeg -y -r 25 -f image2pipe -vcodec ppm -i output.ppm -vcodec libx264 -preset veryslow -pix_fmt yuv420p -crf 25 -threads 0 -bf 0 -movflags +faststart output.mp4

./GourceLog2Midi/gtl2m.sh.php -i output.log -o output.mid -f 25  --notes "B,F,G#,C#"

fluidsynth -R 1 -r 44100 -O s16 -T mp3 -F Poutput.mp3 /usr/share/sounds/sf2/FluidR3_GM.sf2 output.mid

ffmpeg -i output.mp4 -i output.mp3 -i background-music.mp3.mp3 -map 0:v -map 1:a -map 2:a -c copy -shortest -movflags +faststart output-with-sound.mp4

~~~


