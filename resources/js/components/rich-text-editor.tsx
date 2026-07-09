import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Redo,
    Strikethrough,
    Underline as UnderlineIcon,
    Undo,
} from 'lucide-react';

interface RichTextEditorProps {
    content: string;
    onChange: (html: string) => void;
    placeholder?: string;
}

function ToolbarButton({
    onClick,
    active,
    children,
    title,
}: {
    onClick: () => void;
    active?: boolean;
    children: React.ReactNode;
    title: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={title}
            className={[
                'rounded p-1.5 transition-colors',
                active
                    ? 'bg-indigo-100 text-indigo-700'
                    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700',
            ].join(' ')}
        >
            {children}
        </button>
    );
}

export function RichTextEditor({
    content,
    onChange,
    placeholder = 'Write your notice content...',
}: RichTextEditorProps) {
    const editor = useEditor({
        extensions: [
            StarterKit,
            Underline,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Link.configure({ openOnClick: false }),
            Placeholder.configure({ placeholder }),
        ],
        content,
        onUpdate: ({ editor }) => {
            onChange(editor.getHTML());
        },
    });

    if (!editor) return null;

    const setLink = () => {
        const url = window.prompt('Enter URL');
        if (url) {
            editor.chain().focus().setLink({ href: url }).run();
        }
    };

    const iconSize = 16;

    return (
        <div className="overflow-hidden rounded-lg border border-gray-300 focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
            <div className="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50 px-2 py-1.5">
                <ToolbarButton
                    onClick={() => editor.chain().focus().toggleBold().run()}
                    active={editor.isActive('bold')}
                    title="Bold"
                >
                    <Bold size={iconSize} />
                </ToolbarButton>
                <ToolbarButton
                    onClick={() => editor.chain().focus().toggleItalic().run()}
                    active={editor.isActive('italic')}
                    title="Italic"
                >
                    <Italic size={iconSize} />
                </ToolbarButton>
                <ToolbarButton
                    onClick={() => editor.chain().focus().toggleUnderline().run()}
                    active={editor.isActive('underline')}
                    title="Underline"
                >
                    <UnderlineIcon size={iconSize} />
                </ToolbarButton>
                <ToolbarButton
                    onClick={() => editor.chain().focus().toggleStrike().run()}
                    active={editor.isActive('strike')}
                    title="Strikethrough"
                >
                    <Strikethrough size={iconSize} />
                </ToolbarButton>

                <div className="mx-1 h-5 w-px bg-gray-300" />

                <ToolbarButton
                    onClick={() =>
                        editor.chain().focus().setTextAlign('left').run()
                    }
                    active={editor.isActive({ textAlign: 'left' })}
                    title="Align left"
                >
                    <AlignLeft size={iconSize} />
                </ToolbarButton>
                <ToolbarButton
                    onClick={() =>
                        editor.chain().focus().setTextAlign('center').run()
                    }
                    active={editor.isActive({ textAlign: 'center' })}
                    title="Align center"
                >
                    <AlignCenter size={iconSize} />
                </ToolbarButton>
                <ToolbarButton
                    onClick={() =>
                        editor.chain().focus().setTextAlign('right').run()
                    }
                    active={editor.isActive({ textAlign: 'right' })}
                    title="Align right"
                >
                    <AlignRight size={iconSize} />
                </ToolbarButton>

                <div className="mx-1 h-5 w-px bg-gray-300" />

                <ToolbarButton
                    onClick={() =>
                        editor.chain().focus().toggleBulletList().run()
                    }
                    active={editor.isActive('bulletList')}
                    title="Bullet list"
                >
                    <List size={iconSize} />
                </ToolbarButton>
                <ToolbarButton
                    onClick={() =>
                        editor.chain().focus().toggleOrderedList().run()
                    }
                    active={editor.isActive('orderedList')}
                    title="Ordered list"
                >
                    <ListOrdered size={iconSize} />
                </ToolbarButton>

                <div className="mx-1 h-5 w-px bg-gray-300" />

                <ToolbarButton onClick={setLink} title="Insert link">
                    <LinkIcon size={iconSize} />
                </ToolbarButton>

                <div className="mx-1 h-5 w-px bg-gray-300" />

                <ToolbarButton
                    onClick={() => editor.chain().focus().undo().run()}
                    title="Undo"
                >
                    <Undo size={iconSize} />
                </ToolbarButton>
                <ToolbarButton
                    onClick={() => editor.chain().focus().redo().run()}
                    title="Redo"
                >
                    <Redo size={iconSize} />
                </ToolbarButton>
            </div>

            <EditorContent
                editor={editor}
                className="prose prose-sm max-w-none px-3 py-2 focus:outline-none [&_.tiptap]:min-h-[150px] [&_.tiptap]:outline-none [&_.tiptap_p.is-editor-empty:first-child::before]:text-gray-400 [&_.tiptap_p.is-editor-empty:first-child::before]:float-left [&_.tiptap_p.is-editor-empty:first-child::before]:content-[attr(data-placeholder)] [&_.tiptap_p.is-editor-empty:first-child::before]:pointer-events-none [&_.tiptap_p.is-editor-empty:first-child::before]:h-0"
            />
        </div>
    );
}
