import Parchment from "parchment";
import Container from "quill/blots/container";
import BlockEmbed from 'quill/blots/block';
const GUARD_TEXT = "\uFEFF";

export class SpoilerContentBlot extends BlockEmbed {

    static blotName = "spoiler-content";
    static className = "spoiler-content";
    static tagName = 'div';
    static defaultChild = GUARD_TEXT;

    static create() {
        const domNode = super.create();
        domNode.classList.add('spoiler-content');
        return domNode;
    }

    static value(node) {
        return node.innerHTML;
    }
}



export default class SpoilerBlot extends Container {

    static blotName = 'spoiler';
    static className = 'spoiler';
    static tagName = 'div';
    static scope = Parchment.Scope.BLOCK_BLOT;
    static defaultChild = 'spoiler-content';
    static allowedChildren = [SpoilerContentBlot];

    // constructor(props) {
    //     super(props);
    //     this.state = { text: '' };
    // }

    static create() {
        const domNode = super.create();
        domNode.classList.add('spoiler');
        return domNode;
    }

    replace(target) {
        if (target.statics.blotName !== this.statics.blotName) {
            const item = Parchment.create(this.statics.defaultChild);
            target.moveChildren(item);
            this.appendChild(item);
        }
        super.replace(target);
    }
}
