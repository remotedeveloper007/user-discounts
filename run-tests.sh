#!/bin/bash
echo ""
echo -e "\e[42m\e[30m PASS \e[0m Unit Tests\n"
./vendor/bin/phpunit --testdox 2>&1 | grep -v "Remotedeveloper007\|PHPUnit\|Runtime:\|Configuration:\|^\.\.\|^Time:\|^Memory:\|^$" | sed 's/ ✔/ \x1b[32m✓\x1b[0m/g' | awk '/^OK/{print ""; print} !/^OK/{print}'
