FROM node:24-bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        chromium \
        fonts-liberation \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/lighthouse

COPY package.json package-lock.json ./

RUN npm ci

ENV CHROME_PATH=/usr/bin/chromium
ENV PATH="/opt/lighthouse/node_modules/.bin:${PATH}"

WORKDIR /workspace
