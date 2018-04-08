#include <stdio.h>
#include <gtk/gtk.h>
#include <webkit2/webkit2.h>

/**
* tähän voisi liittää comlistenerin koodi lukemaan /dev/ttyUSB0
* tai /dev/tty2, riippuen asemasta - ja tällöin ei tarvisi koko
* WebKit -palikkaa
*/

// callback -funktiot ikkunan sulkemiselle ja webviewin poistamiselle ------
static void destroyWindowCallback(GtkWidget* widget, GtkWidget* window) {
    gtk_main_quit();
}

static gboolean closeWebViewCallback(WebKitWebView* webView, GtkWidget* window) {
    gtk_widget_destroy(window);
    return TRUE;
}
// -------------------------------------------------------------------------


int main(int argc, char* argv[]) {
    gtk_init(&argc, &argv);
    // alusta ikkuna (800x600 on yleisin resoluutio asemissa. varsinkin Idescoissa)
    GtkWidget *mainwnd = gtk_window_new(GTK_WINDOW_TOPLEVEL);
    gtk_window_set_default_size(GTK_WINDOW(mainWnd), 800, 600);
    // alusta webview
    WebKitWebView *wv = WEBKIT_WEB_VIEW(webkit_web_view_new());
    gtk_container_add(GTK_CONTAINER(mainwnd), GTK_WIDGET(wv));
    // liitetään callback -funktiot signaaleihin "destroy" ja "close"
    g_signal_connect(mainwnd, "destroy", G_CALLBACK(destroyWindowCallback), NULL);
    g_signal_connect(wv, "close", G_CALLBACK(closeWebViewCallback), mainwnd);
    // asetetaan web-interfacen osoite
    webkit_web_view_load_uri(wv, "http://www.abcdefg.fi/wta_api/index.php?mobile=1");
    // asetetaan pääfokus webviewille ja näytetään ikkuna
    gtk_widget_grab_focus(GTK_WIDGET(wv));
    gtk_widget_show_all(mainwnd);
    gtk_main();

    return 0;
}
