if (document.getElementById('editor')){
    sceditor.formats.bbcode.set("list", {
        html: function(element, attrs, content) {
            var type = (attrs.defaultattr === '1' ? 'ol' : 'ul');

            return '<' + type + '>' + content + '</' + type + '>';
        },
        breakAfter: false
    })
        .set("ul", { format: function($elm, content) { return '[list]' + content +'[/list]'; }})
        .set("ol", { format: function($elm, content) { return '[list=1]' + content +'[/list]'; }})
        .set("li", { format: function($elm, content) { return '[*]' + content; }})
        .set("*", { excludeClosing: true, isInline: false });

    let textarea = document.getElementById('editor');

    sceditor.create(textarea, {
        format: 'bbcode',
        plugins: 'undo',
        toolbar: 'bold,italic,underline|quote,bulletlist|image',
        emoticonsEnabled: false
    });
    document.getElementsByClassName("sceditor-container")[0].style = "";
}


