import { BaseShape, type Shape as ShapeContract } from './base.js';
import { EventEmitter } from 'node:events';

interface Serializable {
    serialize(): string;
}

export class Circle extends BaseShape implements ShapeContract, Serializable, Disposable {
    constructor(private readonly radius: number) {
        super('circle');
    }

    area(): number {
        return Math.PI * this.radius ** 2;
    }

    serialize(): string {
        return JSON.stringify({ radius: this.radius });
    }

    [Symbol.dispose](): void {}
}

export class Bus extends EventEmitter {}
