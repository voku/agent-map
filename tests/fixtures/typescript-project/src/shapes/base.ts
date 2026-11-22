/** A measurable shape. */
export interface Shape {
    area(): number;
    describe(prefix: string, ...rest: string[]): string;
}

export interface Named extends Shape {
    readonly name: string;
}

export abstract class BaseShape implements Shape {
    static count = 0;

    constructor(protected readonly label: string) {
        BaseShape.count++;
    }

    abstract area(): number;

    describe(prefix: string, ...rest: string[]): string {
        return [prefix, this.label, ...rest].join(' ');
    }

    protected static create(label: string): void {}

    #secret(): void {}

    private readonly onResize = (width: number, height: number): void => {};
}
