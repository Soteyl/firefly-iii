FROM node:22-alpine

WORKDIR /srv
COPY package.json ./
RUN npm install --omit=dev --no-package-lock
COPY app.mjs ./

CMD ["node", "app.mjs"]
