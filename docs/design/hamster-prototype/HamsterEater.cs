using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Reflection;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using Microsoft.Win32;

namespace HamsterEater
{
    public enum Mood
    {
        Sleep,
        Waking,
        Eating,
        Happy,
        Error,
        Pet
    }

    public class Particle
    {
        public string Kind;
        public double X;
        public double Y;
        public double VX;
        public double VY;
        public double Life;
        public double Total;
        public double Size;
        public double Angle;
        public double Spin;
        public Color Color;

        public double Alpha
        {
            get
            {
                if (Total <= 0.0) return 0.0;
                double v = Life / Total;
                if (v < 0.0) v = 0.0;
                if (v > 1.0) v = 1.0;
                return Math.Pow(v, 0.7);
            }
        }
    }

    public class HamsterView : FrameworkElement
    {
        private const double FrameX = 26.0;
        private const double FrameY = 55.0;
        private const double FrameW = 128.0;
        private const double FrameH = 120.0;
        private const double FeetX = 90.0;
        private const double FeetY = 169.0;

        private static readonly double[] EatDurations = { 0.12, 0.08, 0.10, 0.16, 0.10, 0.08, 0.10, 0.20 };
        private const double EatLoop = 0.94;

        private BitmapSource sleepFrame;
        private BitmapSource wakeFrame;
        private BitmapSource happyFrame;
        private readonly List<BitmapSource> eatFrames = new List<BitmapSource>();
        private readonly List<Particle> particles = new List<Particle>();
        private readonly Queue<string> meals = new Queue<string>();
        private readonly DispatcherTimer timer;
        private readonly Random random = new Random();

        private DateTime lastTick = DateTime.UtcNow;
        private DateTime moodStart = DateTime.UtcNow;
        private DateTime eatStart = DateTime.UtcNow;
        private DateTime bubbleStart = DateTime.MinValue;
        private DateTime bubbleUntil = DateTime.MinValue;

        private Mood mood = Mood.Sleep;
        private int eatIndex;
        private int lastEatIndex = -1;
        private int eatLoops = 2;
        private string currentMeal;
        private string bubbleText = "";
        private string bubbleKind = "info";
        private bool held;
        private bool dragHover;
        private string lastError = "";

        public Mood CurrentMood { get { return mood; } }
        public bool HasArt { get { return sleepFrame != null || wakeFrame != null || happyFrame != null || eatFrames.Count > 0; } }
        public string LastError { get { return lastError; } }

        public HamsterView()
        {
            LoadFrames();
            RenderOptions.SetBitmapScalingMode(this, BitmapScalingMode.HighQuality);
            Focusable = false;
            timer = new DispatcherTimer(DispatcherPriority.Render);
            timer.Interval = TimeSpan.FromMilliseconds(30);
            timer.Tick += Timer_Tick;
            timer.Start();
        }

        private static BitmapSource LoadBitmap(Assembly assembly, string name)
        {
            try
            {
                Stream stream = assembly.GetManifestResourceStream(name);
                if (stream == null) return null;
                using (stream)
                {
                    BitmapImage image = new BitmapImage();
                    image.BeginInit();
                    image.CacheOption = BitmapCacheOption.OnLoad;
                    image.StreamSource = stream;
                    image.EndInit();
                    image.Freeze();
                    return image;
                }
            }
            catch
            {
                return null;
            }
        }

        private void LoadFrames()
        {
            Assembly assembly = Assembly.GetExecutingAssembly();
            sleepFrame = LoadBitmap(assembly, "sleep.png");
            wakeFrame = LoadBitmap(assembly, "wake.png");
            happyFrame = LoadBitmap(assembly, "happy.png");
            for (int i = 1; i <= 8; i++)
            {
                BitmapSource frame = LoadBitmap(assembly, string.Format(CultureInfo.InvariantCulture, "eat_{0:00}.png", i));
                if (frame != null) eatFrames.Add(frame);
            }
            if (sleepFrame == null) sleepFrame = wakeFrame;
            if (wakeFrame == null) wakeFrame = sleepFrame;
            if (happyFrame == null) happyFrame = eatFrames.Count > 3 ? eatFrames[3] : sleepFrame;
        }

        private void Timer_Tick(object sender, EventArgs e)
        {
            DateTime now = DateTime.UtcNow;
            double dt = (now - lastTick).TotalSeconds;
            if (dt < 0.0) dt = 0.0;
            if (dt > 0.25) dt = 0.25;
            lastTick = now;
            Advance(now);
            UpdateParticles(dt);
            InvalidateVisual();
        }

        private double EatDuration()
        {
            return eatLoops * EatLoop;
        }

        private int EatIndexAt(DateTime now)
        {
            double elapsed = (now - eatStart).TotalSeconds;
            double local = elapsed % EatLoop;
            double upto = 0.0;
            for (int i = 0; i < EatDurations.Length; i++)
            {
                upto += EatDurations[i];
                if (local < upto) return i;
            }
            return EatDurations.Length - 1;
        }

        private void Advance(DateTime now)
        {
            double elapsed = (now - moodStart).TotalSeconds;
            if (mood == Mood.Waking)
            {
                if (elapsed >= 0.36) BeginEating(now);
            }
            else if (mood == Mood.Eating)
            {
                if ((now - eatStart).TotalSeconds >= EatDuration())
                {
                    ToHappy(now);
                }
                else
                {
                    int index = EatIndexAt(now);
                    if (index != lastEatIndex)
                    {
                        if (index == 1 || index == 3 || index == 7)
                            SpawnCrumbs(index == 1 ? 2 : 4);
                        lastEatIndex = index;
                    }
                    eatIndex = index;
                }
            }
            else if (mood == Mood.Happy)
            {
                if (elapsed >= 0.72)
                {
                    if (meals.Count > 0) StartNextMeal(now);
                    else SleepNow(now);
                }
            }
            else if (mood == Mood.Error)
            {
                if (elapsed >= 1.75)
                {
                    if (meals.Count > 0) StartNextMeal(now);
                    else SleepNow(now);
                }
            }
            else if (mood == Mood.Pet)
            {
                if (elapsed >= 0.95) SleepNow(now);
            }
        }

        private string DisplayName()
        {
            if (currentMeal == null) return "that file";
            string name = Path.GetFileName(currentMeal);
            if (name.Length > 30) name = name.Substring(0, 14) + "..." + name.Substring(name.Length - 13);
            return name;
        }

        public void EnqueueFiles(string[] files)
        {
            DateTime now = DateTime.UtcNow;
            int added = 0;
            for (int i = 0; i < files.Length; i++)
            {
                string path = files[i];
                try
                {
                    if (File.Exists(path))
                    {
                        meals.Enqueue(path);
                        added++;
                    }
                }
                catch
                {
                }
            }
            if (added == 0)
            {
                SetBubble("Only files, please.", 1.4, "error", now);
                return;
            }
            if (mood == Mood.Waking || mood == Mood.Eating)
            {
                SetBubble(meals.Count + " more for later", 1.6, "info", now);
            }
            else if (mood == Mood.Happy || mood == Mood.Error)
            {
                // The queued meal starts when the current beat finishes.
            }
            else
            {
                StartNextMeal(now);
            }
        }

        private void StartNextMeal(DateTime now)
        {
            if (meals.Count == 0)
            {
                SleepNow(now);
                return;
            }
            currentMeal = meals.Dequeue();
            long size = 0;
            try { size = new FileInfo(currentMeal).Length; }
            catch { }
            eatLoops = size < 2000000 ? 2 : 3;
            mood = Mood.Waking;
            moodStart = now;
            eatIndex = 0;
            lastEatIndex = -1;
            SetBubble("Ooh, " + DisplayName(), 1.2, "info", now);
        }

        private void BeginEating(DateTime now)
        {
            if (currentMeal == null)
            {
                SleepNow(now);
                return;
            }
            string error;
            if (!TrashFile(currentMeal, out error))
            {
                lastError = error;
                mood = Mood.Error;
                moodStart = now;
                SetBubble("Couldn't recycle " + DisplayName(), 1.8, "error", now);
                return;
            }
            mood = Mood.Eating;
            moodStart = now;
            eatStart = now;
            eatIndex = 0;
            lastEatIndex = -1;
            SetBubble("nom nom - " + DisplayName(), EatDuration() + 0.45, "info", now);
            SpawnCrumbs(2);
        }

        private bool TrashFile(string path, out string error)
        {
            error = "";
            try
            {
                if (!File.Exists(path))
                {
                    error = "the file is already gone";
                    return false;
                }
                Microsoft.VisualBasic.FileIO.FileSystem.DeleteFile(
                    path,
                    Microsoft.VisualBasic.FileIO.UIOption.OnlyErrorDialogs,
                    Microsoft.VisualBasic.FileIO.RecycleOption.SendToRecycleBin);
                if (File.Exists(path))
                {
                    error = "the file is still there";
                    return false;
                }
                return true;
            }
            catch (Exception ex)
            {
                error = ex.Message;
                return false;
            }
        }

        private void ToHappy(DateTime now)
        {
            mood = Mood.Happy;
            moodStart = now;
            eatIndex = 0;
            SpawnHeart(1);
            if (meals.Count > 0)
                SetBubble("Yum! " + meals.Count + " more...", 0.75, "success", now);
            else
                SetBubble("Yum!", 0.7, "success", now);
        }

        private void SleepNow(DateTime now)
        {
            mood = Mood.Sleep;
            moodStart = now;
            eatIndex = 0;
            lastEatIndex = -1;
            currentMeal = null;
            ClearBubble();
        }

        public void Pet()
        {
            if (mood != Mood.Sleep) return;
            mood = Mood.Pet;
            moodStart = DateTime.UtcNow;
            SpawnHeart(1);
            SetBubble("eep!", 0.9, "info", moodStart);
        }

        public void SetHeld(bool value)
        {
            held = value;
            InvalidateVisual();
        }

        public void SetDragHover(bool value, int count)
        {
            dragHover = value;
            if (value)
            {
                string text = count == 1 ? "Drop to feed!" : "Drop to feed " + count + "!";
                SetBubble(text, 2.0, "drop", DateTime.UtcNow);
            }
            else if (bubbleKind == "drop")
            {
                ClearBubble();
            }
            InvalidateVisual();
        }

        public void ShowBubble(string text, double seconds, string kind)
        {
            SetBubble(text, seconds, kind, DateTime.UtcNow);
        }

        private void SetBubble(string text, double seconds, string kind, DateTime now)
        {
            bubbleText = text;
            bubbleKind = kind;
            bubbleStart = now;
            bubbleUntil = now.AddSeconds(seconds);
            InvalidateVisual();
        }

        private void ClearBubble()
        {
            bubbleText = "";
            bubbleKind = "info";
            bubbleUntil = DateTime.MinValue;
        }

        private bool BubbleActive(DateTime now)
        {
            return bubbleText.Length > 0 && now < bubbleUntil;
        }

        private BitmapSource PoseFrame()
        {
            if (mood == Mood.Eating && eatFrames.Count > 0)
                return eatFrames[eatIndex % eatFrames.Count];
            if (mood == Mood.Happy || mood == Mood.Pet)
                return happyFrame != null ? happyFrame : sleepFrame;
            if (mood == Mood.Waking || mood == Mood.Error || held || dragHover)
                return wakeFrame != null ? wakeFrame : sleepFrame;
            return sleepFrame != null ? sleepFrame : (eatFrames.Count > 0 ? eatFrames[0] : null);
        }

        protected override void OnRender(DrawingContext dc)
        {
            base.OnRender(dc);
            DateTime now = DateTime.UtcNow;
            DrawShadow(dc);
            DrawHamster(dc, now);
            DrawParticles(dc);
            if (mood == Mood.Sleep && !held && !dragHover && !BubbleActive(now))
                DrawZzz(dc, now);
            if (BubbleActive(now))
                DrawBubble(dc, now);
        }

        private void DrawShadow(DrawingContext dc)
        {
            RadialGradientBrush brush = new RadialGradientBrush();
            brush.GradientStops.Add(new GradientStop(Color.FromArgb(88, 20, 12, 8), 0.0));
            brush.GradientStops.Add(new GradientStop(Color.FromArgb(34, 20, 12, 8), 0.55));
            brush.GradientStops.Add(new GradientStop(Color.FromArgb(0, 20, 12, 8), 1.0));
            dc.PushTransform(new ScaleTransform(1.0, 0.13, FeetX, FeetY));
            dc.DrawEllipse(brush, null, new Point(FeetX, FeetY), 50.0, 50.0);
            dc.Pop();
        }

        private void DrawHamster(DrawingContext dc, DateTime now)
        {
            double sx = 1.0;
            double sy = 1.0;
            double dx = 0.0;
            double dy = 0.0;
            double elapsed = (now - moodStart).TotalSeconds;

            if (mood == Mood.Sleep && !held)
            {
                double breath = Math.Sin(elapsed * Math.PI * 2.0 / 4.4);
                sx = 1.0 + 0.012 * breath;
                sy = 1.0 - 0.008 * breath;
                dy = 0.8 * breath;
            }
            else if (mood == Mood.Waking)
            {
                double t = elapsed / 0.36;
                if (t < 0.0) t = 0.0;
                if (t > 1.0) t = 1.0;
                double squash = Math.Sin(t * Math.PI) * 0.045;
                sx = 1.0 + squash;
                sy = 1.0 - squash * 0.8;
            }
            else if (mood == Mood.Happy || mood == Mood.Pet)
            {
                double t = elapsed / 0.72;
                if (t < 0.0) t = 0.0;
                if (t > 1.0) t = 1.0;
                dy = -Math.Sin(t * Math.PI) * 6.0;
                sx = 1.0 + 0.02 * Math.Sin(t * Math.PI);
                sy = 1.0 - 0.02 * Math.Sin(t * Math.PI);
            }
            else if (mood == Mood.Error)
            {
                double t = elapsed / 1.75;
                if (t < 0.0) t = 0.0;
                if (t > 1.0) t = 1.0;
                dx = Math.Sin(elapsed * 62.0) * 6.0 * Math.Pow(1.0 - t, 1.4);
            }
            else if (mood == Mood.Eating)
            {
                dy = Math.Sin(elapsed * Math.PI * 2.0 / 0.34) * 1.2;
            }
            if (held) dy -= 3.0;

            TransformGroup group = new TransformGroup();
            group.Children.Add(new ScaleTransform(sx, sy, FeetX, FeetY));
            group.Children.Add(new TranslateTransform(dx, dy));
            dc.PushTransform(group);

            BitmapSource frame = PoseFrame();
            if (frame != null)
            {
                dc.DrawImage(frame, new Rect(FrameX, FrameY, FrameW, FrameH));
            }
            else
            {
                DrawFallback(dc);
            }
            dc.Pop();
        }

        private void DrawFallback(DrawingContext dc)
        {
            SolidColorBrush orange = new SolidColorBrush(Color.FromRgb(232, 117, 27));
            SolidColorBrush cream = new SolidColorBrush(Color.FromRgb(246, 231, 197));
            SolidColorBrush ink = new SolidColorBrush(Color.FromRgb(43, 26, 19));
            dc.DrawEllipse(orange, new Pen(ink, 3.0), new Point(90, 110), 48, 54);
            dc.DrawEllipse(cream, null, new Point(90, 127), 24, 26);
            dc.DrawEllipse(cream, null, new Point(70, 100), 22, 18);
            dc.DrawEllipse(cream, null, new Point(110, 100), 22, 18);
            Pen line = new Pen(ink, 2.0);
            line.StartLineCap = PenLineCap.Round;
            line.EndLineCap = PenLineCap.Round;
            dc.DrawLine(line, new Point(64, 94), new Point(76, 94));
            dc.DrawLine(line, new Point(104, 94), new Point(116, 94));
            dc.DrawEllipse(new SolidColorBrush(Color.FromRgb(199, 107, 109)), null, new Point(90, 108), 4, 3);
        }

        private void DrawZzz(DrawingContext dc, DateTime now)
        {
            double elapsed = (now - moodStart).TotalSeconds;
            for (int i = 0; i < 3; i++)
            {
                double phase = (elapsed / 2.6 + i / 3.0) % 1.0;
                if (phase > 0.94) continue;
                double fade = Math.Sin(Math.PI * Math.Min(1.0, phase / 0.9));
                double size = 12.0 + 9.0 * phase;
                double x = 66.0 + 9.0 * phase + 2.0 * Math.Sin(phase * Math.PI * 2.0);
                double y = 82.0 - 27.0 * phase;
                dc.PushOpacity(fade);
                FormattedText shadow = MakeText("z", 1000.0, 1, size, new SolidColorBrush(Color.FromArgb(150, 31, 42, 68)), true);
                FormattedText text = MakeText("z", 1000.0, 1, size, new SolidColorBrush(Color.FromRgb(197, 220, 255)), true);
                dc.DrawText(shadow, new Point(x + 1.4, y + 1.7));
                dc.DrawText(text, new Point(x, y));
                dc.Pop();
            }
        }

        private FormattedText MakeText(string text, double maxWidth, int maxLines, double size, Brush brush, bool bold)
        {
            Typeface typeface = new Typeface(
                new FontFamily("Segoe UI"),
                FontStyles.Normal,
                bold ? FontWeights.DemiBold : FontWeights.Normal,
                FontStretches.Normal);
            FormattedText ft = new FormattedText(
                text,
                CultureInfo.CurrentUICulture,
                FlowDirection.LeftToRight,
                typeface,
                size,
                brush,
                VisualTreeHelper.GetDpi(this).PixelsPerDip);
            ft.MaxTextWidth = maxWidth;
            ft.MaxLineCount = maxLines;
            ft.Trimming = TextTrimming.CharacterEllipsis;
            return ft;
        }

        private void DrawBubble(DrawingContext dc, DateTime now)
        {
            double age = (now - bubbleStart).TotalSeconds;
            double left = (bubbleUntil - now).TotalSeconds;
            double alpha = Math.Min(1.0, Math.Min(age / 0.12, left / 0.22));
            if (alpha <= 0.0) return;

            bool isError = bubbleKind == "error";
            Color fillColor = isError ? Color.FromRgb(255, 241, 236) : Color.FromRgb(255, 249, 234);
            Color borderColor = isError ? Color.FromRgb(168, 53, 39) : Color.FromRgb(43, 26, 19);
            Color textColor = isError ? Color.FromRgb(168, 53, 39) : Color.FromRgb(58, 36, 24);

            double fontSize = 11.0;
            FormattedText measure = MakeText(bubbleText, 1000.0, 1, fontSize, new SolidColorBrush(textColor), false);
            double textWidth = measure.WidthIncludingTrailingWhitespace;
            double width = Math.Min(164.0, Math.Max(60.0, textWidth + 20.0));
            double height = 27.0;
            double x = (ActualWidth - width) / 2.0;
            double y = 3.0;
            double centerX = ActualWidth / 2.0;
            double tailHalf = Math.Min(15.0, width / 2.0 - 8.0);

            StreamGeometry tail = new StreamGeometry();
            using (StreamGeometryContext ctx = tail.Open())
            {
                ctx.BeginFigure(new Point(centerX - tailHalf, y + height - 1.0), true, true);
                ctx.LineTo(new Point(centerX, y + height + 8.0), true, false);
                ctx.LineTo(new Point(centerX + tailHalf, y + height - 1.0), true, false);
            }
            tail.Freeze();

            Brush fill = new SolidColorBrush(fillColor);
            Pen border = new Pen(new SolidColorBrush(borderColor), 1.4);
            dc.PushOpacity(alpha);

            dc.PushTransform(new TranslateTransform(0.0, 2.0));
            dc.DrawGeometry(new SolidColorBrush(Color.FromArgb(36, 20, 12, 8)), null, tail);
            dc.DrawRoundedRectangle(new SolidColorBrush(Color.FromArgb(36, 20, 12, 8)), null, new Rect(x, y, width, height), 10, 10);
            dc.Pop();

            dc.DrawGeometry(fill, border, tail);
            dc.DrawRoundedRectangle(fill, border, new Rect(x, y, width, height), 10, 10);

            FormattedText text = MakeText(bubbleText, width - 14.0, 1, fontSize, new SolidColorBrush(textColor), false);
            dc.DrawText(text, new Point(x + (width - text.Width) / 2.0, y + (height - text.Height) / 2.0));
            dc.Pop();
        }

        private void SpawnCrumbs(int count)
        {
            if (particles.Count > 90) return;
            Color[] colors = new Color[]
            {
                Color.FromRgb(246, 231, 197),
                Color.FromRgb(251, 247, 234),
                Color.FromRgb(232, 117, 27),
                Color.FromRgb(227, 207, 164),
                Color.FromRgb(201, 167, 123)
            };
            for (int i = 0; i < count; i++)
            {
                Particle p = new Particle();
                p.Kind = "crumb";
                p.X = 104.0 + random.NextDouble() * 14.0 - 7.0;
                p.Y = 115.0 + random.NextDouble() * 14.0 - 7.0;
                p.VX = random.NextDouble() * 220.0 - 110.0;
                p.VY = random.NextDouble() * 110.0 - 150.0;
                p.Total = 0.45 + random.NextDouble() * 0.45;
                p.Life = p.Total;
                p.Size = 2.2 + random.NextDouble() * 2.4;
                p.Color = colors[random.Next(colors.Length)];
                p.Angle = random.NextDouble() * Math.PI * 2.0;
                p.Spin = random.NextDouble() * 16.0 - 8.0;
                particles.Add(p);
            }
        }

        private void SpawnHeart(int count)
        {
            for (int i = 0; i < count; i++)
            {
                Particle p = new Particle();
                p.Kind = "heart";
                p.X = 90.0 + random.NextDouble() * 44.0 - 22.0;
                p.Y = 89.0 + random.NextDouble() * 16.0 - 8.0;
                p.VX = random.NextDouble() * 32.0 - 16.0;
                p.VY = -30.0 - random.NextDouble() * 18.0;
                p.Total = 0.8 + random.NextDouble() * 0.4;
                p.Life = p.Total;
                p.Size = 9.0 + random.NextDouble() * 4.0;
                p.Color = Color.FromRgb(239, 111, 142);
                p.Angle = random.NextDouble() * 0.3 - 0.15;
                particles.Add(p);
            }
        }

        private void UpdateParticles(double dt)
        {
            for (int i = particles.Count - 1; i >= 0; i--)
            {
                Particle p = particles[i];
                p.Life -= dt;
                p.X += p.VX * dt;
                p.Y += p.VY * dt;
                p.Angle += p.Spin * dt;
                if (p.Kind == "crumb")
                {
                    p.VY += 620.0 * dt;
                    p.VX *= Math.Max(0.0, 1.0 - 1.2 * dt);
                }
                else
                {
                    p.VY -= 12.0 * dt;
                    p.VX *= Math.Max(0.0, 1.0 - 0.6 * dt);
                }
                if (p.Life <= 0.0) particles.RemoveAt(i);
            }
        }

        private void DrawParticles(DrawingContext dc)
        {
            foreach (Particle p in particles)
            {
                double alpha = p.Alpha;
                if (alpha <= 0.0) continue;
                dc.PushOpacity(alpha);
                if (p.Kind == "heart")
                {
                    dc.PushTransform(new TranslateTransform(p.X, p.Y));
                    dc.PushTransform(new RotateTransform(p.Angle * 57.2958));
                    double s = p.Size;
                    StreamGeometry heart = new StreamGeometry();
                    using (StreamGeometryContext ctx = heart.Open())
                    {
                        ctx.BeginFigure(new Point(0.0, s * 0.35), true, true);
                        ctx.BezierTo(new Point(-s * 0.9, -s * 0.2), new Point(-s * 0.55, -s * 0.85), new Point(0.0, -s * 0.35), true, false);
                        ctx.BezierTo(new Point(s * 0.55, -s * 0.85), new Point(s * 0.9, -s * 0.2), new Point(0.0, s * 0.35), true, false);
                    }
                    heart.Freeze();
                    dc.DrawGeometry(new SolidColorBrush(p.Color), new Pen(new SolidColorBrush(Color.FromArgb(150, 120, 40, 55)), 1.0), heart);
                    dc.Pop();
                    dc.Pop();
                }
                else
                {
                    dc.PushTransform(new TranslateTransform(p.X, p.Y));
                    dc.PushTransform(new RotateTransform(p.Angle * 57.2958));
                    dc.DrawRectangle(new SolidColorBrush(p.Color), null, new Rect(-p.Size / 2.0, -p.Size / 2.0, p.Size, p.Size));
                    dc.Pop();
                    dc.Pop();
                }
                dc.Pop();
            }
        }
    }

    public class HamsterWindow : Window
    {
        private readonly HamsterView view;
        private bool pressed;
        private bool moved;
        private Point pressScreen;
        private double pressLeft;
        private double pressTop;

        public HamsterView View { get { return view; } }

        public HamsterWindow()
        {
            WindowStyle = WindowStyle.None;
            AllowsTransparency = true;
            Background = Brushes.Transparent;
            ShowInTaskbar = false;
            Topmost = true;
            ResizeMode = ResizeMode.NoResize;
            Width = 180.0;
            Height = 175.0;
            WindowStartupLocation = WindowStartupLocation.Manual;
            ShowActivated = false;
            Title = "Hamster Eater";
            SnapsToDevicePixels = true;

            Grid root = new Grid();
            root.Background = Brushes.Transparent;
            root.AllowDrop = true;
            view = new HamsterView();
            root.Children.Add(view);
            Content = root;

            Rect work = SystemParameters.WorkArea;
            Left = work.Right - Width - 42.0;
            Top = work.Bottom - Height - 12.0;

            AllowDrop = true;
            root.DragEnter += OnDragEnter;
            root.DragOver += OnDragOver;
            root.DragLeave += OnDragLeave;
            root.Drop += OnDrop;

            PreviewMouseLeftButtonDown += OnMouseDown;
            MouseMove += OnMouseMove;
            PreviewMouseLeftButtonUp += OnMouseUp;

            ContextMenu = BuildMenu();
            root.ContextMenu = ContextMenu;
            SourceInitialized += OnSourceInitialized;
        }

        private ContextMenu BuildMenu()
        {
            ContextMenu menu = new ContextMenu();
            MenuItem feed = new MenuItem();
            feed.Header = "Feed a file...";
            feed.Click += delegate
            {
                OpenFileDialog dialog = new OpenFileDialog();
                dialog.Title = "Feed the hamster";
                dialog.Filter = "All files (*.*)|*.*";
                dialog.Multiselect = true;
                if (dialog.ShowDialog(this) == true)
                {
                    view.EnqueueFiles(dialog.FileNames);
                }
            };
            menu.Items.Add(feed);

            MenuItem recenter = new MenuItem();
            recenter.Header = "Move to bottom-right";
            recenter.Click += delegate { PlaceBottomRight(); };
            menu.Items.Add(recenter);

            MenuItem about = new MenuItem();
            about.Header = "About Hamster Eater";
            about.Click += delegate
            {
                MessageBox.Show(
                    this,
                    "Hamster Eater\n\nDrag me around. Drop a file on me and I'll eat it (it goes to the Recycle Bin). Folders are not snacks.\n\nRight-click for this menu.",
                    "About Hamster Eater",
                    MessageBoxButton.OK,
                    MessageBoxImage.Information);
            };
            menu.Items.Add(new Separator());
            menu.Items.Add(about);
            menu.Items.Add(new Separator());

            MenuItem quit = new MenuItem();
            quit.Header = "Quit Hamster Eater";
            quit.Click += delegate { Close(); };
            menu.Items.Add(quit);
            return menu;
        }

        private void PlaceBottomRight()
        {
            Rect work = SystemParameters.WorkArea;
            Left = work.Right - Width - 42.0;
            Top = work.Bottom - Height - 12.0;
        }

        private void ClampToScreen()
        {
            Rect work = SystemParameters.WorkArea;
            double minLeft = work.Left - Width + 80.0;
            double maxLeft = work.Right - 80.0;
            double minTop = work.Top;
            double maxTop = work.Bottom - 80.0;
            if (Left < minLeft) Left = minLeft;
            if (Left > maxLeft) Left = maxLeft;
            if (Top < minTop) Top = minTop;
            if (Top > maxTop) Top = maxTop;
        }

        private void OnSourceInitialized(object sender, EventArgs e)
        {
            try
            {
                IntPtr hwnd = new WindowInteropHelper(this).Handle;
                int ex = GetWindowLong(hwnd, -20);
                ex = (ex | 0x00000080) & ~0x00040000;
                SetWindowLong(hwnd, -20, ex);
            }
            catch
            {
            }
        }

        private void OnMouseDown(object sender, MouseButtonEventArgs e)
        {
            if (e.ChangedButton != MouseButton.Left) return;
            pressScreen = PointToScreen(e.GetPosition(this));
            pressLeft = Left;
            pressTop = Top;
            pressed = true;
            moved = false;
            view.SetHeld(true);
            CaptureMouse();
            e.Handled = true;
        }

        private void OnMouseMove(object sender, MouseEventArgs e)
        {
            if (!pressed || e.LeftButton != MouseButtonState.Pressed) return;
            Point current = PointToScreen(e.GetPosition(this));
            double dx = current.X - pressScreen.X;
            double dy = current.Y - pressScreen.Y;
            if (!moved && Math.Abs(dx) + Math.Abs(dy) >= 4.0) moved = true;
            if (moved)
            {
                Left = pressLeft + dx;
                Top = pressTop + dy;
            }
            e.Handled = true;
        }

        private void OnMouseUp(object sender, MouseButtonEventArgs e)
        {
            if (e.ChangedButton != MouseButton.Left) return;
            if (!pressed) return;
            pressed = false;
            ReleaseMouseCapture();
            view.SetHeld(false);
            if (moved) ClampToScreen();
            else view.Pet();
            e.Handled = true;
        }

        private static string[] FileDropPaths(DragEventArgs e)
        {
            if (!e.Data.GetDataPresent(DataFormats.FileDrop)) return new string[0];
            string[] all = e.Data.GetData(DataFormats.FileDrop) as string[];
            if (all == null) return new string[0];
            List<string> files = new List<string>();
            for (int i = 0; i < all.Length; i++)
            {
                try
                {
                    if (File.Exists(all[i])) files.Add(all[i]);
                }
                catch
                {
                }
            }
            return files.ToArray();
        }

        private void OnDragEnter(object sender, DragEventArgs e)
        {
            string[] files = FileDropPaths(e);
            if (files.Length == 0)
            {
                e.Effects = DragDropEffects.None;
                e.Handled = true;
                view.SetDragHover(false, 0);
                view.ShowBubble("Only files, please.", 1.4, "error");
                return;
            }
            e.Effects = DragDropEffects.Copy;
            e.Handled = true;
            view.SetDragHover(true, files.Length);
        }

        private void OnDragOver(object sender, DragEventArgs e)
        {
            string[] files = FileDropPaths(e);
            if (files.Length == 0)
            {
                e.Effects = DragDropEffects.None;
                e.Handled = true;
                return;
            }
            e.Effects = DragDropEffects.Copy;
            e.Handled = true;
            view.SetDragHover(true, files.Length);
        }

        private void OnDragLeave(object sender, DragEventArgs e)
        {
            view.SetDragHover(false, 0);
            e.Handled = true;
        }

        private void OnDrop(object sender, DragEventArgs e)
        {
            string[] files = FileDropPaths(e);
            view.SetDragHover(false, 0);
            e.Handled = true;
            e.Effects = files.Length == 0 ? DragDropEffects.None : DragDropEffects.Copy;
            if (files.Length > 0) view.EnqueueFiles(files);
            else view.ShowBubble("Only files, please.", 1.4, "error");
        }

        [System.Runtime.InteropServices.DllImport("user32.dll")]
        private static extern int GetWindowLong(IntPtr hwnd, int index);

        [System.Runtime.InteropServices.DllImport("user32.dll")]
        private static extern int SetWindowLong(IntPtr hwnd, int index, int newStyle);
    }

    public static class Program
    {
        [STAThread]
        public static void Main(string[] args)
        {
            Application app = new Application();
            app.ShutdownMode = ShutdownMode.OnMainWindowClose;
            HamsterWindow window = new HamsterWindow();
            app.MainWindow = window;

#if SMOKE
            string feedPath = null;
            for (int i = 0; i < args.Length; i++)
            {
                if (args[i] == "--feed" && i + 1 < args.Length) feedPath = args[i + 1];
            }
            if (feedPath != null)
            {
                DispatcherTimer feedTimer = new DispatcherTimer();
                feedTimer.Interval = TimeSpan.FromSeconds(1.0);
                feedTimer.Tick += delegate
                {
                    feedTimer.Stop();
                    if (File.Exists(feedPath))
                    {
                        window.View.EnqueueFiles(new string[] { feedPath });
                    }
                };
                feedTimer.Start();
            }
            DispatcherTimer endTimer = new DispatcherTimer();
            endTimer.Interval = TimeSpan.FromSeconds(8.0);
            endTimer.Tick += delegate
            {
                endTimer.Stop();
                try
                {
                    string dir = Path.GetDirectoryName(Assembly.GetExecutingAssembly().Location);
                    string result = "mood=" + window.View.CurrentMood + Environment.NewLine +
                                    "has_art=" + window.View.HasArt + Environment.NewLine +
                                    "width=" + window.Width + Environment.NewLine +
                                    "height=" + window.Height + Environment.NewLine +
                                    "topmost=" + window.Topmost + Environment.NewLine +
                                    "taskbar=" + window.ShowInTaskbar + Environment.NewLine +
                                    "feed_exists=" + (feedPath != null && File.Exists(feedPath)) + Environment.NewLine +
                                    "error=" + window.View.LastError + Environment.NewLine;
                    File.WriteAllText(Path.Combine(dir, "smoke_result.txt"), result);
                }
                catch
                {
                }
                window.Close();
            };
            endTimer.Start();
#endif
            app.Run(window);
        }
    }
}
