export function legacyHelper(first, second = 2) {
    return first + second;
}

export class LegacyBox {
    open() {
        return true;
    }

    static of(value) {
        return new LegacyBox(value);
    }
}

export const LEGACY_VERSION = '1.0';
