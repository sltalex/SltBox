namespace DeskBox.Tests;

public sealed class WidgetForegroundContractTests
{
    [Fact]
    public void WidgetForeground_DoesNotLeakIntoSettingsOrOtherRegularWindows()
    {
        string theme = Read("src/DeskBox/Services/ThemeService.cs");
        string palette = Read("src/DeskBox/Helpers/ForegroundResourceScope.cs");
        Assert.DoesNotContain("ForegroundResourceScope.Apply(rootElement, foreground)", theme, StringComparison.Ordinal);
        Assert.DoesNotContain("ForegroundResourceScope.Apply(Application.Current.Resources, foreground)", theme, StringComparison.Ordinal);
        Assert.Contains("AppearancePreviewChanged += OnAppearancePreviewChanged", theme, StringComparison.Ordinal);
        Assert.Contains("TextFillColorPrimaryBrush", palette, StringComparison.Ordinal);
        Assert.Contains("TextControlForeground", palette, StringComparison.Ordinal);
        Assert.Contains("ListViewItemForeground", palette, StringComparison.Ordinal);
        string search = Read("src/DeskBox/Views/SearchPopupWindow.xaml.cs");
        Assert.Contains("_themeService.ApplyToWindow(this)", search, StringComparison.Ordinal);
    }

    [Fact]
    public void LightWidgetSurfaces_InstallTheirOwnDarkTextScope()
    {
        string scope = Read("src/DeskBox/Helpers/WidgetSurfaceForegroundScope.cs");
        string foreground = Read("src/DeskBox/Views/WidgetWindowBase.Foreground.cs");
        string search = Read("src/DeskBox/Controls/WidgetContents/SearchWidgetContent.xaml.cs");
        string quickCapture = Read("src/DeskBox/Controls/WidgetContents/QuickCaptureSurfaceContent.xaml.cs");
        string monitor = Read("src/DeskBox/Controls/WidgetContents/SystemMonitorWidgetContent.cs");
        string todo = Read("src/DeskBox/Controls/WidgetContents/TodoWidgetContent.DragDrop.cs");

        Assert.Contains("IsLight", scope, StringComparison.Ordinal);
        Assert.Contains("ForegroundResourceScope.Apply(", scope, StringComparison.Ordinal);
        Assert.Contains("?? DarkText", scope, StringComparison.Ordinal);
        Assert.Contains("ApplySearchSurfaceForeground", search, StringComparison.Ordinal);
        Assert.Contains("respectPreferredForeground: true", quickCapture, StringComparison.Ordinal);
        Assert.Contains("WidgetSurfaceForegroundScope.ApplyForBackground(card, background)", monitor, StringComparison.Ordinal);
        Assert.Contains("respectPreferredForeground: true", todo, StringComparison.Ordinal);
        Assert.Contains("PreferredForegroundColorProperty", scope, StringComparison.Ordinal);
        Assert.Contains("FindPreferredForegroundColor", scope, StringComparison.Ordinal);
        Assert.Contains("SetPreferredForegroundColor", foreground, StringComparison.Ordinal);
    }

    [Fact]
    public void SettingsSurface_ExposesPaletteAndColorControlsWithoutTextEdge()
    {
        string xaml = Read("src/DeskBox/Views/SettingsWindow.xaml");
        string bridge = Read(
            "src/DeskBox/Features/Appearance/AppearanceSettingsViewModel.AotBindableProperties.cs");

        Assert.Contains("AvailableForegroundModeOptions", xaml, StringComparison.Ordinal);
        Assert.Contains("ForegroundColorHex", xaml, StringComparison.Ordinal);
        Assert.Contains("nameof(ForegroundColorHex)", bridge, StringComparison.Ordinal);
        // The text edge experiment was removed in 1.5.1 (too many platform
        // traps for the value it added); nothing may quietly reintroduce a
        // shadow surface, host or setting.
        Assert.DoesNotContain("WidgetTextEdge", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("WidgetTextEdge", bridge, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("src/DeskBox/Views/ContentWidgetWindow.xaml")]
    public void WidgetRoots_ProvideLocalSemanticBrushesWithoutDetachedShadowHost(string path)
    {
        string xaml = Read(path);

        Assert.Contains("x:Key=\"TextFillColorPrimaryBrush\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Key=\"TextFillColorSecondaryBrush\"", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("WidgetTextShadowHost", xaml, StringComparison.Ordinal);
    }

    [Fact]
    public void TextEdgeFeature_LeavesNoManagerHostOrSettingBehind()
    {
        // The text edge (composition shadow behind widget text) was dropped in
        // 1.5.1: GetAlphaMask snapshots, per-surface budgets and host lifetime
        // kept producing widget freezes, so the whole feature goes — manager,
        // window and capsule mounts, the popover mount, and the setting.
        string foreground = Read(
            "src/DeskBox/Views/WidgetWindowBase.Foreground.cs");
        string shell = Read("src/DeskBox/Controls/WidgetShell.xaml.cs");
        string popover = Read(
            "src/DeskBox/Controls/WidgetContents/FileSurfaceContent.StackPopover.cs");
        string settings = Read("src/DeskBox/Models/AppSettings.cs");
        string foregroundSettings = Read(
            "src/DeskBox/Services/WidgetForegroundSettings.cs");
        string menu = Read("src/DeskBox/Services/WidgetForegroundMenuBuilder.cs");

        Assert.Contains("CleanupWidgetForegroundAppearance", foreground, StringComparison.Ordinal);
        Assert.DoesNotContain("TextEdge", foreground, StringComparison.Ordinal);
        Assert.DoesNotContain("TextEdge", shell, StringComparison.Ordinal);
        Assert.DoesNotContain("TextShadow", popover, StringComparison.Ordinal);
        Assert.DoesNotContain("WidgetTextEdgeMode", settings, StringComparison.Ordinal);
        Assert.DoesNotContain("EdgeMode", foregroundSettings, StringComparison.Ordinal);
        Assert.DoesNotContain("TextEdge", menu, StringComparison.Ordinal);
        Assert.False(File.Exists(TestPaths.FromRepository(
            "src/DeskBox/Views/WidgetTextShadowManager.cs")));
        Assert.False(File.Exists(TestPaths.FromRepository(
            "src/DeskBox/Views/WidgetWindowBase.TextEdge.cs")));
    }

    [Fact]
    public void TheWidgetMenu_ExposesPerWidgetForegroundOverrides()
    {
        Assert.Contains(
            "WidgetForegroundMenuBuilder.Create",
            Read("src/DeskBox/Views/ContentWidgetWindow.Commands.cs"),
            StringComparison.Ordinal);
    }

    [Fact]
    public void WeatherContent_UsesWidgetPaletteWithoutDetachedThemeOverrides()
    {
        string weatherCode = Read(
            "src/DeskBox/Controls/WidgetContents/WeatherWidgetContent.xaml.cs");
        string windowCode = Read("src/DeskBox/Views/ContentWidgetWindow.xaml.cs");

        // A weather-local RequestedTheme makes ThemeResource labels resolve
        // against framework brushes instead of the widget's mutable palette.
        Assert.Contains(
            "RequestedTheme = ElementTheme.Default;",
            weatherCode,
            StringComparison.Ordinal);
        Assert.Contains(
            "RootGrid.RequestedTheme = ElementTheme.Default;",
            weatherCode,
            StringComparison.Ordinal);
        Assert.DoesNotContain(
            "RootGrid.RequestedTheme = !_viewModel.UsesRichSkin",
            weatherCode,
            StringComparison.Ordinal);

        // The collapsed weather capsule must inherit the same palette as the
        // expanded content instead of forcing a second light/dark theme.
        Assert.DoesNotContain(
            "UseLightForeground: usesRichSkin",
            windowCode,
            StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("src/DeskBox/Controls/WidgetShell.xaml")]
    [InlineData("src/DeskBox/Controls/FileItemSurface.xaml")]
    [InlineData("src/DeskBox/Controls/WidgetContents/FileSurfaceContent.xaml")]
    [InlineData("src/DeskBox/Controls/WidgetContents/GlanceWidgetContent.xaml")]
    [InlineData("src/DeskBox/Controls/WidgetContents/MusicWidgetContent.xaml")]
    [InlineData("src/DeskBox/Controls/WidgetContents/QuickCaptureSurfaceContent.xaml")]
    [InlineData("src/DeskBox/Controls/WidgetContents/SearchWidgetContent.xaml")]
    [InlineData("src/DeskBox/Controls/WidgetContents/TodoWidgetContent.xaml")]
    [InlineData("src/DeskBox/Controls/WidgetContents/WeatherWidgetContent.xaml")]
    public void WidgetContentRoots_InheritTheWidgetPrimaryForeground(string path)
    {
        Assert.Contains(
            "Foreground=\"{ThemeResource TextFillColorPrimaryBrush}\"",
            Read(path),
            StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("src/DeskBox/Views/ContentWidgetWindow.xaml")]
    public void WidgetRoots_RedirectDefaultNativeTextStatesToLocalSemanticBrushes(
        string path)
    {
        string xaml = Read(path);

        Assert.Contains("x:Key=\"DefaultTextForegroundThemeBrush\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Key=\"TextControlForeground\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Key=\"TextControlPlaceholderForeground\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Key=\"GridViewItemForeground\"", xaml, StringComparison.Ordinal);
        Assert.Contains("x:Key=\"ListViewItemForeground\"", xaml, StringComparison.Ordinal);
    }

    [Fact]
    public void CodeGeneratedText_ResolvesTheHostingWidgetResourceScope()
    {
        string markdown = Read("src/DeskBox/Controls/MarkdownDocumentView.cs");
        string stackPopover = Read(
            "src/DeskBox/Controls/WidgetContents/FileSurfaceContent.StackPopover.cs");
        string quickCapture = Read(
            "src/DeskBox/Controls/WidgetContents/QuickCaptureSurfaceContent.xaml.cs");
        string todo = Read(
            "src/DeskBox/Controls/WidgetContents/TodoWidgetContent.EditingAndUndo.cs");
        string neutralBrush = Read("src/DeskBox/Helpers/NeutralInteractionBrush.cs");

        Assert.Contains("_contentForeground = Foreground ??", markdown, StringComparison.Ordinal);
        Assert.Contains(
            "NeutralInteractionBrush.ResolveThemedResource",
            markdown,
            StringComparison.Ordinal);
        Assert.Contains("ApplyStackPopoverForegroundResources(content)", stackPopover, StringComparison.Ordinal);
        Assert.Contains(
            "NeutralInteractionBrush.ResolveThemedResource",
            quickCapture,
            StringComparison.Ordinal);
        Assert.Contains(
            "NeutralInteractionBrush.ResolveThemedResource",
            todo,
            StringComparison.Ordinal);
        Assert.Contains("VisualTreeHelper.GetParent(current)", neutralBrush, StringComparison.Ordinal);
        Assert.Contains("candidate.Resources.TryGetValue(key", neutralBrush, StringComparison.Ordinal);
    }

    private static string Read(string relativePath) =>
        File.ReadAllText(TestPaths.FromRepository(relativePath));
}
