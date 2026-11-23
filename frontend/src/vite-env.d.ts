/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** URL base de la API, por ejemplo https://api.ejemplo.com/api/v1/reus. Se fija al compilar. */
  readonly VITE_REUS_API_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
