# Image PHP de développement local, avec l'extension GD et le support WebP :
# l'admin redimensionne et convertit les photos avant de les envoyer à Supabase,
# et l'image officielle php:8.3-cli ne contient pas GD.
#
#   docker build -t twins-php -f supabase/Dockerfile.gd supabase
#   docker run --rm -p 8080:8080 -v "$PWD":/app -w /app \
#     -e SUPABASE_URL=... -e SUPABASE_ANON_KEY=... twins-php php -S 0.0.0.0:8080
FROM php:8.3-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libwebp-dev libjpeg62-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-webp --with-jpeg \
 && docker-php-ext-install -j4 gd \
 && rm -rf /var/lib/apt/lists/*
