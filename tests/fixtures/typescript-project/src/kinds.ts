export enum Color {
    Red = 'red',
    Green = 'green',
}

export type Point = {
    x: number;
    y: number;
};

export const ORIGIN: Point = { x: 0, y: 0 };

const internalLimit = 10;

export const distance = (from: Point, to: Point = ORIGIN): number =>
    Math.hypot(from.x - to.x, from.y - to.y);

export const clamp = function (value: number, { min, max }: { min: number; max: number }): number {
    return Math.min(max, Math.max(min, value));
};

export function scale(point: Point, factor: number): Point;
export function scale(point: Point, factorX: number, factorY: number): Point;
export function scale(point: Point, factorX: number, factorY?: number): Point {
    return { x: point.x * factorX, y: point.y * (factorY ?? factorX) };
}

function hidden(): void {}

// A value and a type may share one name.
export const Mode = { Fast: 'fast', Slow: 'slow' } as const;
export type Mode = (typeof Mode)[keyof typeof Mode];

export let mutableCounter = 0;

export { hidden as revealed, internalLimit };

export default function main(argv: string[]): number {
    return argv.length;
}
