/** Bundled by esbuild as a data URL (`--loader:.wav=dataurl`), so the widget stays one file. */
declare module '*.wav' {
    const src: string;
    export default src;
}
