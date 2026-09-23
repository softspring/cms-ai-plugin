import {access, cp, mkdir, rm} from 'node:fs/promises';
import {constants} from 'node:fs';

const assetsRoot = new URL('.', import.meta.url);
const packageRoot = new URL('../', assetsRoot);
const outputs = [
    new URL('./dist/', assetsRoot),
    new URL('./public/', packageRoot),
];
const entries = ['scripts', 'styles'];

for (const output of outputs) {
    await rm(output, {recursive: true, force: true});
    await mkdir(output, {recursive: true});

    for (const entry of entries) {
        const source = new URL(`./${entry}/`, assetsRoot);

        try {
            await access(source, constants.F_OK);
            await cp(source, new URL(`./${entry}/`, output), {recursive: true});
        } catch {
            // Optional asset folders are skipped.
        }
    }
}
