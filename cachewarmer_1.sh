#!/bin/bash

clear

echo "Warming up unity sites with HTTrack ..."

echo "Warming Autism Reviewer..."
httrack https://www.autismreviewer-ni.org.uk// -c5R2b0s0aqzvp0C0r3F "HTTrack cache builder" "-*.jpg" "-*.css" "-*.js" "-*.png" "-*.gif" "-*.jpeg" "-*.ico" "-*/type/*" "-*/topic/*" "-*/date/*" "-*page=*"

echo "Warming Legal Commissioner..."
httrack https://www.legalcommissioner-ni.org.uk// -c5R2b0s0aqzvp0C0r3F "HTTrack cache builder" "-*.jpg" "-*.css" "-*.js" "-*.png" "-*.gif" "-*.jpeg" "-*.ico" "-*/type/*" "-*/topic/*" "-*/date/*" "-*page=*"

echo "Warming Another Way..."
httrack https://www.another-way.org// -c5R2b0s0aqzvp0C0r3F "HTTrack cache builder" "-*.jpg" "-*.css" "-*.js" "-*.png" "-*.gif" "-*.jpeg" "-*.ico" "-*/type/*" "-*/topic/*" "-*/date/*" "-*page=*"

echo "---------------------------------------------------"

echo "Warming complete.:"
