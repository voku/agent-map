import { Circle } from './shapes';
import * as base from './shapes/base';
import DefaultWidget from './view/Widget';

// `Circle` arrives through a re-exporting barrel: its declaration is not provable from this
// import alone, so the name stays as written.
export class Ring extends Circle implements base.Named {
    readonly name = 'ring';
}

export class FancyWidget extends DefaultWidget {}
