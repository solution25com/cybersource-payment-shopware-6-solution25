import { TextEncoder, TextDecoder } from 'util';
Object.assign(global, { TextDecoder, TextEncoder });
global.PluginBaseClass = class {
    constructor() {}
};