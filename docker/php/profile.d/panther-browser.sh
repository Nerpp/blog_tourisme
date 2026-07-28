#!/bin/sh

case ":$PATH:" in
    *:/opt/chrome-for-testing:*) ;;
    *) PATH="/opt/chrome-for-testing:$PATH" ;;
esac

export PATH
