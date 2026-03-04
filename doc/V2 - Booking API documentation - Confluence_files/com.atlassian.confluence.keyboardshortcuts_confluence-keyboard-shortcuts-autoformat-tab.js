WRMCB=function(e){var c=console;if(c&&c.log&&c.error){c.log('Error running batched script.');c.error(e);}}
;
try {
/* module-key = 'com.atlassian.confluence.keyboardshortcuts:confluence-keyboard-shortcuts-autoformat-tab', location = 'js/shortcut-dialog-tab-autoformat.js' */
define('confluence-keyboard-shortcuts/shortcut-dialog-tab-autoformat', [
    'ajs',
    'confluence-keyboard-shortcuts/confluence-keyboard-shortcuts-soy',
    'confluence-keyboard-shortcuts/shortcut-dialog-tab-autoformat-soy',
    'jquery'
], function(
    AJS,
    DialogTemplates,
    templates,
    $
) {
    "use strict";

    // An object containing the new button definitions for the ADG3 styling.
    // TODO: Replace defaults with these definitions when the editor ADG3 styling is made the default setting.
    var adg3Items = {
        strikethrough: {
            context: "autoformat.font_formatting",
            description: templates.strikethroughDescription(),
            action: "~~Strikethrough~~"
        },
        bold: {
            context: "autoformat.font_formatting",
            description: templates.boldDescription(),
            action: "**Bold** or __Bold__"
        },
        italic: {
            context: "autoformat.font_formatting",
            description: templates.italicDescription(),
            action: "*Italic* or _Italic_"
        },
        monospace: {
            context: "autoformat.font_formatting",
            description: templates.monospaceDescription(),
            action: "`Monospace`"
        },
        h1: {
            context: "autoformat.styles",
            description: templates.h1Description(),
            action: "# Heading 1"
        },
        h3: {
            context: "autoformat.styles",
            description: templates.h3Description(),
            action: "### Heading 3"
        },
        bq: {
            context: "autoformat.styles",
            description: templates.bqDescription(),
            action: "\u003e Quote"
        },
        ol: {
            context: "autoformat.lists",
            description: templates.numberedListDescription(),
            action: "1. list"
        }
    };


    /*
     Adds the "Editor Autoformatting" tab to the Keyboard Shortcuts help dialog
     */

    var AutoformatItems = [
        adg3Items.bold,
        adg3Items.strikethrough,
        adg3Items.italic,
        adg3Items.monospace,
        {
            context: "autoformat.tables",
            description: templates.tableDescription(),
            action: "||||| + enter"
        },
        {
            context: "autoformat.tables",
            description: templates.tableWithHeadingsDiscriptions(),
            action: "||heading||heading||"
        },
        adg3Items.h1,
        adg3Items.h3,
        adg3Items.bq,
        {
            context: "autoformat.emoticons",
            img: "check.png",
            action: "(/)"
        },
        {
            context: "autoformat.emoticons",
            img: "smile.png",
            action: ":)"
        },
        adg3Items.ol,
        {
            context: "autoformat.lists",
            description: templates.bulletedListDescription(),
            action: "* bullets"
        },
        {
            context: "autoformat.lists",
            description: templates.inlineTaskListDescription(),
            action: "[] task"
        },
        {
            context: "autoformat.autocomplete",
            description: "Image/Media",
            action: "!"
        },
        {
            context: "autoformat.autocomplete",
            description: "Link",
            action: "["
        },
        {
            context: "autoformat.autocomplete",
            description: "Macro",
            action: "{"
        }
    ];

    var buildShortcutModule = function (title, context, itemBuilder) {
        var module = $(DialogTemplates.keyboardShortcutModule({title: title}));
        var list = module.find("ul");
        var items = getMenuItemsForContext(context);

        for (var i = 0, ii = items.length; i < ii; i++) {
            list.append(
                itemBuilder(items[i])
            );
        }

        return module;
    };

    var buildStandardShortcutModule = function (title, context, itemTemplate) {
        return buildShortcutModule(
            title,
            context,
            function (item) {
                return itemTemplate({output: item.description, type: item.action});
            }
        );
    };

    var buildEmoticonModule = function (title, context) {
        var emoticonResourceUrl = AJS.params.staticResourceUrlPrefix + "/images/icons/emoticons/";
        return buildShortcutModule(
            title,
            context,
            function (item) {
                return templates.emoticonHelpItem(
                    {src: emoticonResourceUrl + item.img, type: item.action}
                );
            }
        );
    };

    var getMenuItemsForContext = function (context) {
        return $.grep(AutoformatItems, function (e) {
            return e.context === context;
        });
    };

    var buildHelpPanel = function () {
        var autoformatHelpPanel = $(DialogTemplates.keyboardShortcutPanel({panelId: 'autoformat-shortcuts-panel'}));
        var autoformatHelpPanelMenu = autoformatHelpPanel.children(".shortcutsmenu");

        autoformatHelpPanelMenu.append(
            buildStandardShortcutModule(
                "Font Formatting",
                "autoformat.font_formatting",
                templates.simpleHelpItem
            )
        );
        autoformatHelpPanelMenu.append(
            buildStandardShortcutModule("Autocomplete",
                "autoformat.autocomplete",
                templates.keyboardShortcutItem
            )
        );
        autoformatHelpPanelMenu.append(
            buildStandardShortcutModule(
                "Tables",
                "autoformat.tables",
                templates.tableHelpItem
            )
        );
        autoformatHelpPanelMenu.append(
            buildStandardShortcutModule(
                "Styles",
                "autoformat.styles",
                templates.styleHelpItem
            ).addClass("styles-module")
        );
        autoformatHelpPanelMenu.append(
            buildEmoticonModule(
                "Emoticons",
                "autoformat.emoticons"
            )
        );
        autoformatHelpPanelMenu.append(
            buildStandardShortcutModule(
                "Lists",
                "autoformat.lists",
                templates.simpleHelpItem
            )
        );

        if (AJS.Meta.get("remote-user")) {
            autoformatHelpPanel.find(".keyboard-shortcut-dialog-panel-header").append(
                templates.configureAutocomplete(
                    {href: AJS.contextPath() + "/users/viewmyeditorsettings.action"}
                )
            );
        }

        return autoformatHelpPanel;
    };

    return buildHelpPanel;

});
}catch(e){WRMCB(e)};