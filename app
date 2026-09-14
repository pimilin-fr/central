#!/bin/bash

ENV_FILE=".env.local"

case "$1" in
    prod)
        echo "Mode PROD"
        sed -i 's/^DATABASE_URL=.*/DATABASE_URL="${DATABASE_PROD_URL}"/' "$ENV_FILE"
        ;;

    demo)
        echo "Mode DEMO"
        sed -i 's/^DATABASE_URL=.*/DATABASE_URL="${DATABASE_DEMO_URL}"/' "$ENV_FILE"
        ;;

    status)
        grep '^DATABASE_URL=' "$ENV_FILE"
        exit 0
        ;;

    *)
        echo "Usage: ./app {prod|demo|status}"
        exit 1
        ;;
esac

php bin/console cache:clear
